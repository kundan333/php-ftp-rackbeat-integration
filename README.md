# php-ftp-rackbeat-integration

This project automates the process of downloading sales orders in XML format from an FTP server, importing them into Rackbeat, and re-uploading order confirmations to the FTP server when the status changes to 'confirmed'.

## Requirements

- PHP 7.4 or higher
- Composer

## Installation

1. Clone the repository:
   ```
   git clone https://github.com/kundan333/php-ftp-rackbeat-integration.git
   ```

2. Navigate to the project directory:
   ```
   cd php-ftp-rackbeat-integration
   ```

3. Install the dependencies using Composer:
   ```
   composer install
   ```

## Configuration

Edit the `src/config/config.php` file to set your FTP and Rackbeat API credentials.

## Usage

1. To process orders, instantiate the `OrderProcessor` class and call the `processOrders()` method:
   ```php
   require 'vendor/autoload.php';

   use YourNamespace\OrderProcessor;

   $orderProcessor = new OrderProcessor();
   $orderProcessor->processOrders();
   ```

2. The `OrderProcessor` will handle downloading orders, importing them into Rackbeat, and managing order confirmations.

## Contributing

Feel free to submit issues or pull requests to improve the project.

## License

This project is licensed under the MIT License. See the LICENSE file for details.