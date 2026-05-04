<?php

declare(strict_types=1);

namespace SaudiZATCA\Facades;

use Illuminate\Support\Facades\Facade;
use SaudiZATCA\ZatcaManager;

/**
 * @method static \SaudiZATCA\Services\CertificateService certificate()
 * @method static \SaudiZATCA\Services\ZatcaAPIService api()
 * @method static \SaudiZATCA\Services\InvoiceService invoice()
 * @method static \SaudiZATCA\Services\QRCodeService qr()
 * @method static \SaudiZATCA\Services\XMLGeneratorService xml()
 * @method static array generateCSR(array $merchantData = [])
 * @method static array processInvoice(\SaudiZATCA\Data\InvoiceData $invoice, \SaudiZATCA\Data\SellerData $seller, ?\SaudiZATCA\Data\BuyerData $buyer = null)
 * @method static string generatePhase1QR(\SaudiZATCA\Data\SellerData $seller, float $total, float $vat, ?\DateTime $timestamp = null)
 *
 * @see \SaudiZATCA\ZatcaManager
 */
class Zatca extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'zatca';
    }
}
