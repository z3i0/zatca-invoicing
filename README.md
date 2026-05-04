# ZATCA E-Invoicing Laravel Package

<p align="center">
  <img src="https://img.shields.io/badge/ZATCA-Phase%202-blue" alt="ZATCA Phase 2">
  <img src="https://img.shields.io/badge/Laravel-10.x%2F11.x%2F12.x%2F13.x-red" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-purple" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="MIT License">
</p>

Complete Laravel package for **ZATCA (Saudi Arabia) E-Invoicing Phase 2** integration. Supports all three environments: **Sandbox**, **Simulation**, and **Production**.

## Features

- **Certificate Management**: CSR generation with secp256k1 ECC keys
- **Compliance CSID**: Request compliance certificates from ZATCA
- **Production CSID**: Request production certificates after passing compliance
- **Invoice Generation**: UBL 2.1 compliant XML for all invoice types
- **Digital Signing**: ECDSA SHA-256 signatures (XAdES-BES)
- **QR Code Generation**: ZATCA TLV format (Phase 1 & Phase 2)
- **Invoice Submission**: Reporting (B2C) and Clearance (B2B)
- **Credit/Debit Notes**: Full support for invoice adjustments
- **Arabic Support**: Bilingual invoice support
- **Multi-Environment**: Sandbox, Simulation, and Production
- **Comprehensive Logging**: Detailed logging of all operations
- **Database Storage**: Track all invoices and certificates
- **Full Test Coverage**: Unit and Feature tests included

## Requirements

- PHP 8.1+
- Laravel 10.x / 11.x / 12.x / 13.x
- OpenSSL extension with EC support
- GMP extension (recommended)
- ext-dom, ext-mbstring, ext-json, ext-xmlwriter, ext-curl

## Installation

```bash
composer require saudizatca/laravel-zatca
```

Publish configuration:

```bash
php artisan vendor:publish --tag=zatca-config
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

Add to your `.env` file:

```env
# Environment: sandbox | simulation | production
ZATCA_ENVIRONMENT=sandbox

# Seller Information
ZATCA_SELLER_NAME_EN="Your Company Name"
ZATCA_SELLER_NAME_AR="اسم شركتك بالعربي"
ZATCA_VAT_NUMBER=300000000000003

# Address
ZATCA_SELLER_STREET="King Fahd Road"
ZATCA_SELLER_BUILDING="1234"
ZATCA_SELLER_CITY="Riyadh"
ZATCA_SELLER_DISTRICT="Al Olaya"
ZATCA_SELLER_POSTAL_CODE="12345"

# CSR Configuration
ZATCA_CSR_ORGANIZATION="Your Company Name"
ZATCA_CSR_ORGANIZATION_UNIT="IT Department"
ZATCA_CSR_COMMON_NAME="Your Company Name"

# Sandbox Credentials (from ZATCA Developer Portal)
ZATCA_SANDBOX_USERNAME=your_sandbox_username
ZATCA_SANDBOX_PASSWORD=your_sandbox_password

# Debug (optional)
ZATCA_DEBUG_ENABLED=true
```

## Quick Start

### 1. Check Status

```bash
php artisan zatca:status
```

### 2. Generate CSR

```bash
php artisan zatca:csr --vat=300000000000003 --org="Your Company"
```

### 3. Request Compliance CSID

Get OTP from ZATCA portal, then:

```bash
php artisan zatca:compliance-csid --otp=123456
```

### 4. Request Production CSID (after compliance tests pass)

```bash
php artisan zatca:production-csid
```

### 5. Submit Invoices

```bash
# Simplified invoice (B2C)
php artisan zatca:report --number=INV-001 --total=115 --vat=15

# Standard invoice (B2B) - requires XML file
php artisan zatca:clear --xml=/path/to/signed_invoice.xml
```

## Programmatic Usage

### Generate CSR

```php
use SaudiZATCA\Facades\Zatca;

$result = Zatca::certificate()->generateCSR([
    'organization_identifier' => '300000000000003',
    'organization' => 'Your Company',
    'common_name' => 'Your Company',
    'street' => 'King Fahd Road',
    'city' => 'Riyadh',
]);

// $result['csr'] - CSR content
// $result['private_key'] - Private key content
```

### Create and Submit Invoice

```php
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\InvoiceLineData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;

// Create seller
$seller = SellerData::fromConfig(config('zatca.seller'));

// Create buyer (for B2B/standard invoices)
$buyer = new BuyerData(
    name: 'Buyer Company',
    vatNumber: '300000000000004',
    city: 'Jeddah'
);

// Create invoice
$invoice = new InvoiceData(
    invoiceNumber: 'INV-001',
    issueDate: new DateTime(),
    lines: [
        new InvoiceLineData('Product A', 2, 50.0, 15.0),
        new InvoiceLineData('Product B', 1, 100.0, 15.0),
    ],
    type: InvoiceData::TYPE_STANDARD // or TYPE_SIMPLIFIED
);

// Process (generate XML, sign, QR, submit)
$result = Zatca::processInvoice($invoice, $seller, $buyer);

// $result['uuid']
// $result['invoice_hash']
// $result['signed_xml']
// $result['qr_code']
// $result['submission']
```

### Generate QR Code (Phase 1 - Basic)

```php
use SaudiZATCA\Facades\Zatca;
use SaudiZATCA\Data\SellerData;

$seller = new SellerData('Your Company', '300000000000003');

$qrCode = Zatca::generatePhase1QR($seller, 115.0, 15.0);
// Returns Base64-encoded TLV QR data
```

### Working with Existing XML

```php
use SaudiZATCA\Facades\Zatca;

// Generate XML only
$xml = Zatca::xml()->generate($invoice, $seller, $buyer);

// Sign existing XML
$signedResult = Zatca::invoice()->signInvoice($xml, $invoice);

// Generate QR for signed invoice
$qrCode = Zatca::invoice()->generateQRCode($seller, $invoice, $signedResult['invoice_hash'], $signedResult);

// Submit to ZATCA
$submission = Zatca::invoice()->submitToZatca($signedResult['signed_xml'], $signedResult['invoice_hash'], $invoice);
```

## Invoice Types

| Type | Description | Submission Method |
|------|-------------|-------------------|
| `standard` | B2B Tax Invoice | Clearance (real-time) |
| `simplified` | B2C Simplified Invoice | Reporting (within 24h) |
| `credit_note` | Credit Note | Clearance |
| `debit_note` | Debit Note | Clearance |

## Artisan Commands

| Command | Description |
|---------|-------------|
| `zatca:status` | Check integration status |
| `zatca:csr` | Generate CSR |
| `zatca:compliance-csid` | Request compliance CSID |
| `zatca:production-csid` | Request production CSID |
| `zatca:report` | Submit simplified invoice |
| `zatca:clear` | Submit standard invoice |
| `zatca:validate` | Validate XML or QR code |

## Database Models

### ZatcaCertificate

Stores certificate information:

```php
use SaudiZATCA\Models\ZatcaCertificate;

// Get active production certificate
$cert = ZatcaCertificate::active()
    ->environment('production')
    ->first();

// Check validity
if ($cert && $cert->isValid()) {
    // Use certificate
}
```

### ZatcaInvoice

Tracks all invoices:

```php
use SaudiZATCA\Models\ZatcaInvoice;

// Get pending invoices
$pending = ZatcaInvoice::pending()->get();

// Get today's invoices
$today = ZatcaInvoice::today()->get();

// Get by status
$submitted = ZatcaInvoice::status('submitted')->get();
```

### ZatcaLog

Detailed operation logs:

```php
use SaudiZATCA\Models\ZatcaLog;

// Get recent errors
$errors = ZatcaLog::errors()->recent(24)->get();

// Get API logs
$apiLogs = ZatcaLog::category('api')->recent()->get();
```

## Environments

### Sandbox
- For initial development and testing
- Uses ZATCA Developer Portal
- Pre-configured test credentials

### Simulation
- Pre-production testing
- Requires real CSID from compliance
- Tests with real API structure

### Production
- Live environment
- Requires production CSID
- Real invoice submission

## Testing

Run the test suite:

```bash
# Run all tests
vendor/bin/phpunit

# With coverage
vendor/bin/phpunit --coverage-html coverage

# Run specific test
vendor/bin/phpunit --filter=CertificateServiceTest
```

### Test Structure

```
tests/
├── Unit/
│   ├── CertificateServiceTest.php
│   ├── InvoiceServiceTest.php
│   └── QRCodeServiceTest.php
└── Feature/
    ├── APIIntegrationTest.php
    └── InvoiceSubmissionTest.php
```

## Error Handling

All exceptions extend `ZatcaException`:

```php
use SaudiZATCA\Exceptions\ZatcaException;
use SaudiZATCA\Exceptions\CertificateException;
use SaudiZATCA\Exceptions\APIException;
use SaudiZATCA\Exceptions\InvoiceException;

try {
    $result = Zatca::processInvoice($invoice, $seller);
} catch (CertificateException $e) {
    // Handle certificate errors
    Log::error('Certificate: ' . $e->getMessage());
} catch (APIException $e) {
    // Handle API errors
    Log::error('API Error (' . $e->getStatusCode() . '): ' . $e->getMessage());
    if ($e->getDetails()) {
        Log::error('Details: ' . json_encode($e->getDetails()));
    }
} catch (InvoiceException $e) {
    // Handle invoice errors
    Log::error('Invoice: ' . $e->getMessage());
} catch (ZatcaException $e) {
    // Handle general ZATCA errors
    Log::error('ZATCA: ' . $e->getMessage());
}
```

## Logging

Configure logging in `config/zatca.php`:

```php
'logging' => [
    'enabled' => true,
    'channel' => 'zatca', // Create this channel in logging.php
    'level' => 'debug',
    'log_api_requests' => true,
    'log_api_responses' => true,
    'log_sensitive_data' => false, // Set false in production
],
```

Add to `config/logging.php`:

```php
'channels' => [
    'zatca' => [
        'driver' => 'single',
        'path' => storage_path('logs/zatca.log'),
        'level' => 'debug',
    ],
],
```

## Security

- Never commit `.env` files with real credentials
- Use `log_sensitive_data: false` in production
- Store certificates securely (storage path should be outside web root)
- Rotate certificates before expiry
- Use HTTPS for all API communications in production

## Troubleshooting

### Certificate Issues
```bash
# Check OpenSSL EC support
php -r "var_dump(openssl_get_curve_names());"

# Verify CSR content
openssl req -in storage/zatca/certificates/csr.pem -text -noout
```

### API Connection Issues
```bash
# Check ZATCA status
php artisan zatca:status

# Test with debug enabled
ZATCA_DEBUG_ENABLED=true php artisan zatca:report --number=TEST
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/saudizatca/laravel-zatca/issues).

## References

- [ZATCA E-Invoicing Portal](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [ZATCA Developer Portal](https://developer.zatca.gov.sa/)
- [ZATCA Technical Guidelines](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf)
