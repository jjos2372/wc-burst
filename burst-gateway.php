<?php

/**
* Plugin Name: Burst Payment Gateway
* Plugin URI: http://github.com/jjos2372/wc-burst
* Description: Accept BURST on your WooCommerce site in a fast and secure checkout environment.
* Version: 0.0.1
* Author: jjos
* Author URI: https://github.com/jjos2372
*
* @package WordPress
* @author jjos
* @since 5.0.0
*/

add_action('plugins_loaded', 'woocommerce_burst_init', 0);
update_option('woocommerce_hold_stock_minutes_burst', 30);

/**
 * Function checking for payments and for updating the order status.
 * 
 * Every 30 seconds this function is called and check for pending burst order payments.
 */
function check_burst_payments($this_) {

    $args = array(
        'status' => 'on-hold',
        'payment_method' => 'burst',
    );
    $unpaid_orders = wc_get_orders( $args );

    if ( $unpaid_orders ) {
        $burstid = $this_->get_option( 'burstid' );
        $bursthold = $this_->get_option( 'bursthold' );
        $burstnode = $this_->get_option( 'burstnode' );
        $nconfs = $this_->get_option( 'numberofconfirmations');
        $maxzeroconf = (float)$this_->get_option( 'maxamountforzeroconf');

        try {
            // check the node to see what orders were paid
            // get the transactions
            $url = $burstnode;
            $url .= '/burst?requestType=getAccountTransactions&account=' . $burstid . '&lastIndex=100';
            $file = file_get_contents($url, false);
            $transactions = json_decode($file);

            // get the unconfirmed transactions (pending)
            $url = $burstnode;
            $url .= '/burst?requestType=getUnconfirmedTransactions&account=' . $burstid;
            $file = file_get_contents($url, false);
            $transactions_unconf = json_decode($file);
            
            foreach ( $unpaid_orders as $order ) {
                $pending = false;

                $notes = $order->get_customer_order_notes();
                $nqt_value = "";
                foreach ($notes as $note) {
                    if(substr($note->comment_content, 0, 10) === "burst_nqt="){
                        // here we have the burst_nqt amount
                        $nqt_value = substr($note->comment_content, 10);
                        break;
                    }
                }

                // check if this order was paid already
                foreach( $transactions->transactions as $t) {
                    $amountNQT = $t->amountNQT;
                    if($nqt_value === $amountNQT){
                        $pending = true;
                        if(((int)$t->confirmations) >= $nconfs){
                            // order was paid
                            $order->update_status( 'processing', __( 'Order paid with BURST TX: ' . $t->transaction, 'woocommerce' ) );
                            break;
                        }
                    }
                }

                // check if the order is on the unconf list (leave it on-hold if so)
                foreach( $transactions_unconf->unconfirmedTransactions as $t) {
                    $amountNQT = $t->amountNQT;
                    if($nqt_value === $amountNQT){
                        $pending = true;
                        if($order->get_total() < $maxzeroconf){
                            // accept this unconfirmed transaction (only for small amount purchases)
                            $order->update_status( 'processing', __( 'Order accepted with pending transaction from address ' . $t->senderRS, 'woocommerce' ) );
                        }
                        break;
                    }
                }

                $minutes_passed = time() - $order->get_date_created()->getTimestamp();
                $minutes_passed /= 60;

                if(!$pending && $minutes_passed > $bursthold){
                    $order->update_status( 'cancelled', __( 'Unpaid order cancelled - burst time limit reached.', 'woocommerce' ) );
                }
            }
        }
        catch (Exception $e) {
            // Do nothing for now, lets see if the next round works ...
        }
    }

    
    // schedule again the event in 30 seconds
    // (using single events since recurrent events run in an hour or less, so ...)
    wp_schedule_single_event( time() + 30, 'woocommerce_burst_event', array($this_));
}
add_action( 'woocommerce_burst_event', 'check_burst_payments', 10, 1 );


function woocommerce_burst_init() {
	if (!class_exists("WC_Payment_Gateway"))
        return;
        
    include( plugin_dir_path( __FILE__ ) . 'phpqrcode.php');

    /**
    * Burst Payments Gateway Class
    */
    class WC_Burst extends WC_Payment_Gateway {
        public static $log = false;

        function __construct() {
			// Register plugin information
            $this->id		    = 'burst';
			$this->method_title	= 'Burst';
            $this->has_fields   = false;
            $this->supports     = array(
              'products'
            );

            // Create plugin fields and settings
            $this->init_form_fields();
            $this->init_settings();

			// Init default settings
			//if (empty($this->settings['automaticlanguages']))
			//	$this->settings['automaticlanguages'] = 'yes';

			// Get setting values
            foreach ($this->settings as $key => $val)
                $this->$key = $val;

			// Load plugin checkout icon
            $this->icon = PLUGIN_DIR . 'burst-icon.png';

            // Add hooks
            add_action('woocommerce_receipt_burst',                            array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways',              array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            if ($this->logging == 'yes')
                self::$log = wc_get_logger();
            if (!$this->is_valid_for_use())
                $this->enabled = 'no';
        }

        public function is_valid_for_use() {
            // Check if the user FIAT currency is supported, since we need a Coingecko service converting from FIAT to BURST
		    return in_array(get_woocommerce_currency(), array(
                'USD', 'AED', 'ARS', 'AUD', 'BDT', 'BHD', 'BMD', 'BRL', 'CAD', 'CHF','CLP', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP',
                'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'KWD', 'LKR', 'MMK', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PKR',
                'PLN', 'RUB', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'UAH', 'VEF', 'VND', 'ZAR', 'XDR', 'XAG', 'XAU'
            ));
	    }

        /**
        * Initialize Gateway Settings Form Fields.
        */
        function init_form_fields() {
            $this->form_fields = array(
                'general' => array(
					'title'       => __( 'General Settings', 'woocommerce' ),
					'type'        => 'title',
					'description' => '',
				),	
				'enabled'       => array(
                    'title'       => __('Enable', 'burst'),
                    'label'       => __('Enable Burst Payments', 'burst'),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'burstid'     => array(
                    'title'       => __('Burst ID', 'burst'),
                    'type'        => 'text',
                    'description' => __('This is your burst wallet numeric ID for receiving funds.<br />
                        If you do not have one, please create a new address using a
                        <a target="_blank" href="https://www.burst-coin.org/download-wallet">Burst wallet</a>.', 'burst'),
                    'default'     => '3278233074628313816'
                ),
                'burstad'     => array(
                    'title'       => __('Burst Address', 'burst'),
                    'type'        => 'text',
                    'description' => __('This is your burst wallet address for receiving funds.<br />
                        If you do not have one, please create a new address using a
                        <a target="_blank" href="https://www.burst-coin.org/download-wallet">Burst wallet</a>.', 'burst'),
                    'default'     => 'BURST-JJQS-MMA4-GHB4-4ZNZU'
                ),
                'burstnode'     => array(
                    'title'       => __('Burst node', 'burst'),
                    'type'        => 'text',
                    'description' => __('Burst node to connect when checking for payments.<br>Consider running your own node for extra security.', 'burst'),
                    'default'     => 'https://wallet.burstcoin.ro'
                ),
                'bursthold'     => array(
                    'title'       => __('Burst hold duration in minutes', 'burst'),
                    'type'        => 'text',
                    'description' => __('The number of minutes orders are held open, waiting for BURST transfer.<br>After the held period the order is automatically canceled.', 'burst'),
                    'default'     => '30'
                ),
				'maxamountforzeroconf'   => array(
                    'title'       => __('Maximum amount accepted with zero confirmation', 'burst'),
                    'type'        => 'text',
                    'label'       => __('Maximum amount accepted with zero confirmation', 'burst'),
                    'description' => __('This sets the maximum order amount (in your FIAT currency) were zero confimation payments are accepted (pending in mempool are accepted). By default, no payment is accepted with zero confirmations, but you can relax that with this option for small amount payments (like for a cup of coffee).', 'burst'),
                    'default'     => '0'
                ),
				'numberofconfirmations'   => array(
                    'title'       => __('Number of confirmations (blocks) to accept a payment', 'burst'),
                    'type'        => 'text',
                    'label'       => __('Number of confirmations (blocks) to accept a payment', 'burst'),
                    'description' => __('This sets the minimum number of confirmations (blocks) to accept a payment (except zero confirmation payments).', 'burst'),
                    'default'     => '2'
                ),
                /*
				'automaticlanguages'   => array(
                    'title'       => __('Automatic document language', 'burst'),
                    'type'        => 'checkbox',
                    'label'       => __('Automatic document language', 'burst'),
                    'description' => __('This allows producing document matching the current logged user locale. If unchecked, documents are produced in English.', 'burst'),
                    'default'     => 'yes'
                ),
                */
				'userinterface' => array(
					'title'       => __( 'User Interface', 'woocommerce' ),
					'type'        => 'title',
					'description' => '',
				),
				'title'         => array(
                    'title'       => __('Title', 'Burst'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'burst'),
                    'default'     => __('Burst payment', 'burst')
                ),
                'description'   => array(
                    'title'       => __('Description', 'burst'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'burst'),
                    'default'     => 'Pay with BURST.'
                ),
				
				'advanced' => array(
					'title'       => __( 'Advanced options', 'woocommerce' ),
					'type'        => 'title',
					'description' => '',
				),
                'logging'       => array(
                    'title'       => __('Logging', 'burst'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable logging', 'burst'),
                    'description' => __('Checking this will log debugging data.', 'burst'),
                    'default'     => 'no'
                ),
            );
        }

        /**
        * UI - Admin Panel Options
        */
        function admin_options() { ?>
<h3><?php echo __('Burst Payment', 'burst') ?></h3>
<p>
	<?php echo __(
        'A simple and powerful checkout solution for WooCommerce to receive in BURST with <b>no additional fees and no registration</b>, you just need a Burst wallet address.<br><br>
        Payment values are converted from your configured FIAT currency to BURST using <a target="_blank" href="https://www.coingecko.com">Coingecko API</a>.
        Different payment options are shown to the buyer (QR code, link, or address to transfer BURST) in the checkout page.
        You can configure the plugin to accept small values with zero confirmations (instantaneous payment).
        The number of confirmations (blocks) to accept general payments is also configurable.
        Payments are set as on-hold and and event is scheduled to check for the confirmation of on-hold payments and then set them
        as paid after the number of confirmations. After a given number of minutes (which is also configurable),
        unpaid orders are automatically cancelled.', 'burst') ?>
</p>
<table class="form-table">
  <?php $this->generate_settings_html(); ?>
</table>
<?php
        }

        /**
		 * Process the payment and return the result
		 *
		 * This will put the order into on-hold status, reduce inventory levels, and empty customer shopping cart.
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
	
			$order = wc_get_order( $order_id );
			
            if ( $order->get_total() > 0 ) {

                // Get BURST -> FIAT rating and store it in the order

                // First get the currency in use
                // $cur = get_woocommerce_currency();
                $cur = strtolower($order->get_currency());

                $url = "https://api.coingecko.com/api/v3/simple/price?ids=burst&vs_currencies=" . $cur;

                $opts = array(
                    'http'=>array(
                      'method'=>"GET",
                      'header'=>"Accept: application/json"
                    )
                );
                $context = stream_context_create($opts);
                $file = file_get_contents($url, false, $context);
                $obj = json_decode($file);
                $rate = $obj->{'burst'}->{$cur};

                $total_in_burst = $order->get_total()/$rate;
                $total_in_burst_nqt = ceil($total_in_burst*100)*1000000;
                // add the order_id to the value, making it easier to identify the payment later
                $total_in_burst_nqt += $order_id;
                // store this info as an order note
                $order->add_order_note('burst_nqt=' . $total_in_burst_nqt, 1);

                // Mark as on-hold (we're awaiting the payment)
                $order->update_status( 'on-hold', __( 'Awaiting Burst payment confirmation', 'burst' ) );
            } else {
                $order->payment_complete();
            }
			
			// Reduce stock levels
			$order->reduce_order_stock();
			
			// Remove cart
			WC()->cart->empty_cart();
			
			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order )
			);
		}

        private static function write_to_log($text) {
            if (self::$log == null)
                return;
            self::$log->add('burst', $text . "\r\n");
        }

        /**
		 * Output for the order received page.
		 */
		public function thankyou_page( $order_id ) {
            $order = wc_get_order( $order_id );
            
            // schedule the check for payment event in the next 60 seconds
            wp_schedule_single_event( time() + 60, 'woocommerce_burst_event', array($this));
			
            $notes = $order->get_customer_order_notes();

            foreach ($notes as $note) {
                if(substr($note->comment_content, 0, 10) === "burst_nqt="){
                    // here we have the burst_nqt amount
                    $nqt_value = substr($note->comment_content, 10);

                    $png_file = 'tmp/' .$nqt_value.'.png';
                    $url = 'burst://requestBurst?receiver='.$this->get_option( 'burstid' )
                        . '&amountNQT=' . $nqt_value . '&feeNQT=2000000&immutable=true';
                    QRcode::png($url, plugin_dir_path( __FILE__ ) . $png_file, QR_ECLEVEL_L, 3); 

                    $htmlOutput = '<h2>BURST payment pending</h2>';

                    $htmlOutput .= '<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">'.
                    'Now you just need to transfer the amount within <strong>'. $this->get_option( 'bursthold' ) .' minutes</strong>, otherwise the order is automatically canceled.</p>';

                    $htmlOutput .= '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">';

                    $htmlOutput .= '<li class="woocommerce-order-overview__order order">Send this exact amount:<strong>'.
                        substr($nqt_value,0,strlen($nqt_value)-8) . '.' . substr($nqt_value,-8) .' BURST</strong></li>';
                    $htmlOutput .= '<li class="woocommerce-order-overview__order order">To this address:<strong>'.
                        $this->get_option( 'burstad' ) .'</strong></li>';

                    $htmlOutput .= '<li class="woocommerce-order-overview__order order">'.
                        '<a href="'. $url .'"><img src="'. plugin_dir_url( __FILE__ ) . $png_file .'" style="width:200px; height:200px" alt="QRcode"></a></li>';

                    $htmlOutput .= '</ul>';

                    break;
                }
            }

			echo $htmlOutput;
		}

        function receipt_page($order) {
            echo '<p>' . __('Thank you for your order.', 'burst') . '</p>';
        }
    }

    if (!class_exists('WC_Payment_Gateway'))
        return;

    DEFINE('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    /**
    * Add the gateway to woocommerce
    */
    function add_burst_gateway($methods) {
        $methods[] = 'WC_Burst';
        return $methods;
    }
    function burst_load_plugin_textdomain() {
        load_plugin_textdomain('burst', false, dirname(plugin_basename( __FILE__ )) . '/languages/');
    }

    add_action('plugins_loaded', 'burst_load_plugin_textdomain');
	add_filter('woocommerce_payment_gateways', 'add_burst_gateway');
}
