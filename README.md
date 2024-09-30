# Paymento Cryptocurrency Gateway for WHMCS

This repository contains a payment gateway module for WHMCS that integrates with Paymento, allowing you to accept cryptocurrency payments in your WHMCS-powered website.

## Features

- Accept various cryptocurrencies including Bitcoin, Ethereum, and more
- Automatic payment verification
- Supports pending payment status
- Easy to install and configure

## Requirements

- WHMCS version 7.0 or higher
- PHP 7.2 or higher
- cURL extension enabled
- A Paymento merchant account

## Installation

1. Download the latest release from this repository.
2. Extract the contents of the zip file.
3. Upload the `modules` folder to your WHMCS root directory, merging it with the existing `modules` folder.
4. Navigate to Setup > Payment Gateways in your WHMCS admin area.
5. Find "Paymento" in the list of payment gateways and click "Activate".

## Configuration

1. After activation, click on "Manage Existing Gateways" and find Paymento in the list.
2. Enter your Paymento API Key and Secret Key.
3. Configure any additional settings as needed.
4. Click "Save Changes".

## Usage

Once configured, Paymento will appear as a payment option during the WHMCS checkout process. Customers can select it to pay with their preferred cryptocurrency.

## Support

If you encounter any issues or have questions, please open an issue in this GitHub repository.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Disclaimer

This module is provided as-is. Please ensure you test it thoroughly in a staging environment before using it in production.

## Acknowledgements

- Thanks to the WHMCS team for providing a robust platform for web hosting automation.
