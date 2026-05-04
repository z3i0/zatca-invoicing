<?php

declare(strict_types=1);

namespace SaudiZATCA;

use Illuminate\Contracts\Foundation\Application;
use SaudiZATCA\Services\{
    CertificateService,
    ZatcaAPIService,
    InvoiceService,
    QRCodeService,
    XMLGeneratorService
};
use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;

/**
 * ZATCA Manager - Main orchestrator class
 *
 * This is the primary class for interacting with ZATCA functionality.
 * It provides a unified interface to all ZATCA operations.
 */
class ZatcaManager
{
    public function __construct(
        private readonly Application $app
    ) {
    }

    /**
     * Access certificate service
     */
    public function certificate(): CertificateService
    {
        return $this->app->make(CertificateService::class);
    }

    /**
     * Access API service
     */
    public function api(): ZatcaAPIService
    {
        return $this->app->make(ZatcaAPIService::class);
    }

    /**
     * Access invoice service
     */
    public function invoice(): InvoiceService
    {
        return $this->app->make(InvoiceService::class);
    }

    /**
     * Access QR code service
     */
    public function qr(): QRCodeService
    {
        return $this->app->make(QRCodeService::class);
    }

    /**
     * Access XML generator service
     */
    public function xml(): XMLGeneratorService
    {
        return $this->app->make(XMLGeneratorService::class);
    }

    /**
     * Quick method to generate a CSR
     */
    public function generateCSR(array $merchantData = []): array
    {
        return $this->certificate()->generateCSR($merchantData);
    }

    /**
     * Quick method to create and submit an invoice
     */
    public function processInvoice(InvoiceData $invoice, SellerData $seller, ?BuyerData $buyer = null): array
    {
        return $this->invoice()->processInvoice($invoice, $seller, $buyer);
    }

    /**
     * Quick method to generate QR code for Phase 1
     */
    public function generatePhase1QR(SellerData $seller, float $total, float $vat, ?\DateTime $timestamp = null): string
    {
        return $this->qr()->generatePhase1QR($seller, $total, $vat, $timestamp);
    }
}
