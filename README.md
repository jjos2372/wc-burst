# wc-burst: WooCommerce (WordPress) BURST payments
[![GPLv3](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)

[WooCommerce](https://woocommerce.com) clains to have more than 70 million downloads and to power over 28% of all online stores.
It is probably one of the most used e-commerce platforms.

This project consists in a simple and powerful checkout solution for WooCommerce to receive in [BURST](https://www.burst-coin.org/) with zero fee. **No registration required**, you just need a Burst wallet address.

[![Demo video](http://img.youtube.com/vi/XcN5WxqjjGw/0.jpg)](https://www.youtube.com/watch?v=XcN5WxqjjGw "Demo video")

Payment values are converted from your configured FIAT currency to BURST using [Coingecko](https://www.coingecko.com/) API.
Buyers should transfer BURST directly to your Burst address: your wallet, your funds.
There is no third party holding your funds.

Different payment options are shown to the buyer (QR code, link, or address to transfer BURST) in the checkout page.
You can configure the plugin to accept small values with zero confirmations (instantaneous payment).
The number of confirmations (blocks) to accept general payments is also configurable.
Payments are set as on-hold, a cron service check for the confirmation of on-hold payments and then set them as paid after the number of confirmations.

## FIAT Currencies supported

The limitation is actually the [Coingecko](https://www.coingecko.com/) API.
Currently the following currencies are supported:
                'USD', 'AED', 'ARS', 'AUD', 'BDT', 'BHD', 'BMD', 'BRL', 'CAD', 'CHF','CLP', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP',
                'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'KWD', 'LKR', 'MMK', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PKR',
                'PLN', 'RUB', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'UAH', 'VEF', 'VND', 'ZAR', 'XDR', 'XAG', 'XAU'

Recalling that this plugin converts the order total amount from your FIAT currency to BURST.
Then the buyer transfer to your Burst wallet this BURST amount.

## Installation

Download the zip file available in the [releases section](releases) and then upload it as a plugin in your
WordPress admin page.

### Requirements

Tested with WordPress 5.5.2 and WooCommerce 3.6.5.

## License

This code is licensed under [GPLv3](LICENSE).

## Author

jjos

Donation address: BURST-JJQS-MMA4-GHB4-4ZNZU
