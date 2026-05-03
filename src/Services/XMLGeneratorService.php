<?php

declare(strict_types=1);

namespace SaudiZATCA\Services;

use SaudiZATCA\Data\InvoiceData;
use SaudiZATCA\Data\SellerData;
use SaudiZATCA\Data\BuyerData;
use SaudiZATCA\Data\InvoiceLineData;
use SaudiZATCA\Exceptions\InvoiceException;

/**
 * XML Generator Service
 * 
 * Generates UBL 2.1 compliant XML invoices for ZATCA.
 * Supports Standard, Simplified, Credit Note, and Debit Note invoices.
 */
class XMLGeneratorService
{
    private const XMLNS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const XMLNS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const XMLNS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const XMLNS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    
    private \DOMDocument $doc;
    private \DOMElement $root;
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
    }

    /**
     * Generate XML invoice
     * 
     * @return string XML content
     */
    public function generate(InvoiceData $invoice, SellerData $seller, ?BuyerData $buyer = null): string
    {
        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        
        // Create root element
        $rootName = match ($invoice->type) {
            InvoiceData::TYPE_CREDIT_NOTE => 'CreditNote',
            InvoiceData::TYPE_DEBIT_NOTE => 'DebitNote',
            default => 'Invoice',
        };
        
        $this->root = $this->doc->createElementNS(self::XMLNS, $rootName);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::XMLNS_CAC);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::XMLNS_CBC);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::XMLNS_EXT);
        $this->doc->appendChild($this->root);
        
        // Add UBL extensions (for digital signature)
        $this->addUBLExtensions($invoice);
        
        // Add basic invoice information
        $this->addBasicInfo($invoice);
        
        // Add seller information
        $this->addSeller($seller);
        
        // Add buyer information (for standard invoices)
        if ($buyer !== null && $invoice->type !== InvoiceData::TYPE_SIMPLIFIED) {
            $this->addBuyer($buyer);
        }
        
        // Add delivery information
        $this->addDelivery($invoice);
        
        // Add payment means
        $this->addPaymentMeans($invoice);
        
        // Add allowance charge (discounts/charges)
        $this->addAllowanceCharge($invoice);
        
        // Add tax totals
        $this->addTaxTotal($invoice);
        
        // Add legal monetary total
        $this->addLegalMonetaryTotal($invoice);
        
        // Add invoice lines
        $this->addInvoiceLines($invoice);
        
        return $this->doc->saveXML();
    }

    /**
     * Add UBL extensions for digital signature
     */
    private function addUBLExtensions(InvoiceData $invoice): void
    {
        $extContainer = $this->createElement('ext:UBLExtensions');
        
        // Extension for signature
        $ext = $this->createElement('ext:UBLExtension');
        $extContent = $this->createElement('ext:ExtensionContent');
        
        // Placeholder for signature (will be filled during signing)
        $sigElement = $this->doc->createElementNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2',
            'sig:UBLDocumentSignatures'
        );
        $sigElement->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:sig',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2'
        );
        $sigElement->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:sac',
            'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2'
        );
        
        $extContent->appendChild($sigElement);
        $ext->appendChild($extContent);
        $extContainer->appendChild($ext);
        $this->root->appendChild($extContainer);
    }

    /**
     * Add basic invoice information
     */
    private function addBasicInfo(InvoiceData $invoice): void
    {
        // Profile ID
        $profileId = $this->createElement('cbc:ProfileID', 'reporting:1.0');
        $this->root->appendChild($profileId);
        
        // ID (invoice number)
        $id = $this->createElement('cbc:ID', $invoice->invoiceNumber);
        $this->root->appendChild($id);
        
        // UUID
        $uuid = $this->createElement('cbc:UUID', $invoice->getUuid());
        $this->root->appendChild($uuid);
        
        // Issue Date
        $issueDate = $this->createElement('cbc:IssueDate', $invoice->issueDate->format('Y-m-d'));
        $this->root->appendChild($issueDate);
        
        // Issue Time
        $issueTime = $this->createElement('cbc:IssueTime', $invoice->issueDate->format('H:i:s'));
        $this->root->appendChild($issueTime);
        
        // Invoice Type Code
        $typeCode = $this->createElement('cbc:InvoiceTypeCode', $invoice->subTypeCode());
        $typeCode->setAttribute('name', $this->getInvoiceTypeName($invoice));
        $this->root->appendChild($typeCode);
        
        // Document Currency Code
        $currencyCode = $this->createElement('cbc:DocumentCurrencyCode', $invoice->currency);
        $currencyCode->setAttribute('listID', 'ISO 4217 Alpha');
        $this->root->appendChild($currencyCode);
        
        // Tax Currency Code (same as document currency)
        $taxCurrencyCode = $this->createElement('cbc:TaxCurrencyCode', $invoice->currency);
        $this->root->appendChild($taxCurrencyCode);
        
        // Billing Reference (for credit/debit notes)
        if ($invoice->billingReference || $invoice->referenceInvoiceNumber) {
            $this->addBillingReference($invoice);
        }
        
        // Additional Document Reference (PIH - Previous Invoice Hash)
        if ($invoice->previousInvoiceHash) {
            $this->addPIHReference($invoice->previousInvoiceHash);
        } else {
            // For first invoice, use default PIH
            $this->addPIHReference('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyM2NlNTM4MQ==');
        }
        
        // Notes
        if ($invoice->notes) {
            $notes = $this->createElement('cbc:Note', $invoice->notes);
            $this->root->appendChild($notes);
        }
    }

    /**
     * Get invoice type name
     */
    private function getInvoiceTypeName(InvoiceData $invoice): string
    {
        return match ($invoice->type) {
            InvoiceData::TYPE_SIMPLIFIED => '0200000',
            InvoiceData::TYPE_STANDARD => '0100000',
            InvoiceData::TYPE_CREDIT_NOTE => '0300000',
            InvoiceData::TYPE_DEBIT_NOTE => '0400000',
            default => '0100000',
        };
    }

    /**
     * Add billing reference
     */
    private function addBillingReference(InvoiceData $invoice): void
    {
        $billingRef = $this->createElement('cac:BillingReference');
        $invoiceDoc = $this->createElement('cac:InvoiceDocumentReference');
        
        if ($invoice->referenceInvoiceNumber) {
            $refId = $this->createElement('cbc:ID', $invoice->referenceInvoiceNumber);
            $invoiceDoc->appendChild($refId);
        }
        
        if ($invoice->referenceInvoiceDate) {
            $refDate = $this->createElement('cbc:IssueDate', $invoice->referenceInvoiceDate->format('Y-m-d'));
            $invoiceDoc->appendChild($refDate);
        }
        
        $billingRef->appendChild($invoiceDoc);
        $this->root->appendChild($billingRef);
    }

    /**
     * Add PIH reference
     */
    private function addPIHReference(string $hash): void
    {
        $docRef = $this->createElement('cac:AdditionalDocumentReference');
        $id = $this->createElement('cbc:ID', 'PIH');
        $docRef->appendChild($id);
        
        $attachment = $this->createElement('cac:Attachment');
        $embedded = $this->createElement('cbc:EmbeddedDocumentBinaryObject', $hash);
        $embedded->setAttribute('mimeCode', 'plain');
        $attachment->appendChild($embedded);
        $docRef->appendChild($attachment);
        
        $this->root->appendChild($docRef);
    }

    /**
     * Add seller information
     */
    private function addSeller(SellerData $seller): void
    {
        $party = $this->createElement('cac:AccountingSupplierParty');
        $partyElement = $this->createElement('cac:Party');
        
        // Party identification (VAT number)
        $partyId = $this->createElement('cac:PartyIdentification');
        $id = $this->createElement('cbc:ID', $seller->vatNumber);
        $id->setAttribute('schemeID', 'VAT');
        $partyId->appendChild($id);
        $partyElement->appendChild($partyId);
        
        // Address
        if ($seller->street || $seller->city) {
            $address = $this->createElement('cac:PostalAddress');
            
            if ($seller->street) {
                $street = $this->createElement('cbc:StreetName', $seller->street);
                $address->appendChild($street);
            }
            
            if ($seller->building) {
                $building = $this->createElement('cbc:BuildingNumber', $seller->building);
                $address->appendChild($building);
            }
            
            if ($seller->district) {
                $district = $this->createElement('cbc:District', $seller->district);
                $address->appendChild($district);
            }
            
            if ($seller->city) {
                $city = $this->createElement('cbc:CityName', $seller->city);
                $address->appendChild($city);
            }
            
            if ($seller->postalCode) {
                $postal = $this->createElement('cbc:PostalZone', $seller->postalCode);
                $address->appendChild($postal);
            }
            
            $country = $this->createElement('cac:Country');
            $countryCode = $this->createElement('cbc:IdentificationCode', $seller->country);
            $country->appendChild($countryCode);
            $address->appendChild($country);
            
            $partyElement->appendChild($address);
        }
        
        // Party legal entity
        $legalEntity = $this->createElement('cac:PartyLegalEntity');
        $name = $this->createElement('cbc:RegistrationName', $seller->nameEn);
        $legalEntity->appendChild($name);
        $partyElement->appendChild($legalEntity);
        
        $party->appendChild($partyElement);
        $this->root->appendChild($party);
    }

    /**
     * Add buyer information
     */
    private function addBuyer(BuyerData $buyer): void
    {
        $party = $this->createElement('cac:AccountingCustomerParty');
        $partyElement = $this->createElement('cac:Party');
        
        // Party identification
        if ($buyer->vatNumber) {
            $partyId = $this->createElement('cac:PartyIdentification');
            $id = $this->createElement('cbc:ID', $buyer->vatNumber);
            $id->setAttribute('schemeID', 'VAT');
            $partyId->appendChild($id);
            $partyElement->appendChild($partyId);
        }
        
        // Address
        if ($buyer->street || $buyer->city) {
            $address = $this->createElement('cac:PostalAddress');
            
            if ($buyer->street) {
                $street = $this->createElement('cbc:StreetName', $buyer->street);
                $address->appendChild($street);
            }
            
            if ($buyer->building) {
                $building = $this->createElement('cbc:BuildingNumber', $buyer->building);
                $address->appendChild($building);
            }
            
            if ($buyer->city) {
                $city = $this->createElement('cbc:CityName', $buyer->city);
                $address->appendChild($city);
            }
            
            if ($buyer->postalCode) {
                $postal = $this->createElement('cbc:PostalZone', $buyer->postalCode);
                $address->appendChild($postal);
            }
            
            $country = $this->createElement('cac:Country');
            $countryCode = $this->createElement('cbc:IdentificationCode', $buyer->country);
            $country->appendChild($countryCode);
            $address->appendChild($country);
            
            $partyElement->appendChild($address);
        }
        
        // Party legal entity
        $legalEntity = $this->createElement('cac:PartyLegalEntity');
        $name = $this->createElement('cbc:RegistrationName', $buyer->name ?? ' ');
        $legalEntity->appendChild($name);
        $partyElement->appendChild($legalEntity);
        
        $party->appendChild($partyElement);
        $this->root->appendChild($party);
    }

    /**
     * Add delivery information
     */
    private function addDelivery(InvoiceData $invoice): void
    {
        if (!$invoice->deliveryDate) {
            return;
        }
        
        $delivery = $this->createElement('cac:Delivery');
        $date = $this->createElement('cbc:ActualDeliveryDate', $invoice->deliveryDate->format('Y-m-d'));
        $delivery->appendChild($date);
        $this->root->appendChild($delivery);
    }

    /**
     * Add payment means
     */
    private function addPaymentMeans(InvoiceData $invoice): void
    {
        if (!$invoice->paymentMethod) {
            return;
        }
        
        $paymentMeans = $this->createElement('cac:PaymentMeans');
        
        $code = match ($invoice->paymentMethod) {
            'cash' => '10',
            'credit' => '30',
            'bank_transfer' => '42',
            'card' => '48',
            default => '1',
        };
        
        $meansCode = $this->createElement('cbc:PaymentMeansCode', $code);
        $paymentMeans->appendChild($meansCode);
        
        if ($invoice->paidAmount) {
            $paidAmount = $this->createElement('cbc:PaidAmount', (string) $invoice->paidAmount);
            $paidAmount->setAttribute('currencyID', $invoice->currency);
            $paymentMeans->appendChild($paidAmount);
        }
        
        $this->root->appendChild($paymentMeans);
    }

    /**
     * Add allowance charge (discounts and charges)
     */
    private function addAllowanceCharge(InvoiceData $invoice): void
    {
        // Discount
        if ($invoice->totalDiscount && $invoice->totalDiscount > 0) {
            $allowanceCharge = $this->createElement('cac:AllowanceCharge');
            
            $chargeIndicator = $this->createElement('cbc:ChargeIndicator', 'false');
            $allowanceCharge->appendChild($chargeIndicator);
            
            $reasonCode = $this->createElement('cbc:AllowanceChargeReasonCode', '95');
            $allowanceCharge->appendChild($reasonCode);
            
            $reason = $this->createElement('cbc:AllowanceChargeReason', 'Discount');
            $allowanceCharge->appendChild($reason);
            
            $amount = $this->createElement('cbc:Amount', (string) $invoice->totalDiscount);
            $amount->setAttribute('currencyID', $invoice->currency);
            $allowanceCharge->appendChild($amount);
            
            $baseAmount = $this->createElement('cbc:BaseAmount', (string) $invoice->subTotal());
            $baseAmount->setAttribute('currencyID', $invoice->currency);
            $allowanceCharge->appendChild($baseAmount);
            
            $taxCategory = $this->createElement('cac:TaxCategory');
            $taxScheme = $this->createElement('cac:TaxScheme');
            $taxSchemeId = $this->createElement('cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeId);
            $taxCategory->appendChild($taxScheme);
            $allowanceCharge->appendChild($taxCategory);
            
            $this->root->appendChild($allowanceCharge);
        }
    }

    /**
     * Add tax total
     */
    private function addTaxTotal(InvoiceData $invoice): void
    {
        // Main tax total
        $taxTotal = $this->createElement('cac:TaxTotal');
        
        $taxAmount = $this->createElement('cbc:TaxAmount', (string) $invoice->totalTax());
        $taxAmount->setAttribute('currencyID', $invoice->currency);
        $taxTotal->appendChild($taxAmount);
        
        // Subtotals per tax rate
        $taxSubTotals = $this->groupTaxByRate($invoice);
        foreach ($taxSubTotals as $rate => $amount) {
            $subTotal = $this->createElement('cac:TaxSubtotal');
            
            $taxableAmount = $this->createElement('cbc:TaxableAmount', (string) $amount['taxable']);
            $taxableAmount->setAttribute('currencyID', $invoice->currency);
            $subTotal->appendChild($taxableAmount);
            
            $subTaxAmount = $this->createElement('cbc:TaxAmount', (string) $amount['tax']);
            $subTaxAmount->setAttribute('currencyID', $invoice->currency);
            $subTotal->appendChild($subTaxAmount);
            
            $taxCategory = $this->createElement('cac:TaxCategory');
            $taxPercent = $this->createElement('cbc:Percent', (string) $rate);
            $taxCategory->appendChild($taxPercent);
            
            $taxScheme = $this->createElement('cac:TaxScheme');
            $taxSchemeId = $this->createElement('cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeId);
            $taxCategory->appendChild($taxScheme);
            
            $subTotal->appendChild($taxCategory);
            $taxTotal->appendChild($subTotal);
        }
        
        $this->root->appendChild($taxTotal);
    }

    /**
     * Group tax amounts by rate
     * 
     * @return array<float, array{taxable: float, tax: float}>
     */
    private function groupTaxByRate(InvoiceData $invoice): array
    {
        $groups = [];
        
        foreach ($invoice->lines as $line) {
            $rate = $line->taxRate;
            if (!isset($groups[$rate])) {
                $groups[$rate] = ['taxable' => 0, 'tax' => 0];
            }
            $groups[$rate]['taxable'] += $line->netTotal();
            $groups[$rate]['tax'] += $line->calculateTax();
        }
        
        return $groups;
    }

    /**
     * Add legal monetary total
     */
    private function addLegalMonetaryTotal(InvoiceData $invoice): void
    {
        $monetaryTotal = $this->createElement('cac:LegalMonetaryTotal');
        
        // Line extension amount (subtotal before discount)
        $lineExtAmount = $this->createElement('cbc:LineExtensionAmount', (string) $invoice->subTotal());
        $lineExtAmount->setAttribute('currencyID', $invoice->currency);
        $monetaryTotal->appendChild($lineExtAmount);
        
        // Tax exclusive amount
        $taxExclusive = $this->createElement('cbc:TaxExclusiveAmount', (string) $invoice->subTotal());
        $taxExclusive->setAttribute('currencyID', $invoice->currency);
        $monetaryTotal->appendChild($taxExclusive);
        
        // Tax inclusive amount
        $taxInclusive = $this->createElement('cbc:TaxInclusiveAmount', (string) ($invoice->subTotal() + $invoice->totalTax()));
        $taxInclusive->setAttribute('currencyID', $invoice->currency);
        $monetaryTotal->appendChild($taxInclusive);
        
        // Allowance total (discount)
        if ($invoice->totalDiscount) {
            $allowanceTotal = $this->createElement('cbc:AllowanceTotalAmount', (string) $invoice->totalDiscount);
            $allowanceTotal->setAttribute('currencyID', $invoice->currency);
            $monetaryTotal->appendChild($allowanceTotal);
        }
        
        // Payable amount
        $payableAmount = $this->createElement('cbc:PayableAmount', (string) $invoice->totalAmount());
        $payableAmount->setAttribute('currencyID', $invoice->currency);
        $monetaryTotal->appendChild($payableAmount);
        
        $this->root->appendChild($monetaryTotal);
    }

    /**
     * Add invoice lines
     */
    private function addInvoiceLines(InvoiceData $invoice): void
    {
        foreach ($invoice->lines as $index => $line) {
            $lineElement = match ($invoice->type) {
                InvoiceData::TYPE_CREDIT_NOTE => $this->createElement('cac:CreditNoteLine'),
                InvoiceData::TYPE_DEBIT_NOTE => $this->createElement('cac:DebitNoteLine'),
                default => $this->createElement('cac:InvoiceLine'),
            };
            
            // Line ID
            $lineId = $this->createElement('cbc:ID', (string) ($index + 1));
            $lineElement->appendChild($lineId);
            
            // Quantity
            $invoicedQuantity = $this->createElement('cbc:InvoicedQuantity', (string) $line->quantity);
            $invoicedQuantity->setAttribute('unitCode', $line->unitCode);
            $lineElement->appendChild($invoicedQuantity);
            
            // Line extension amount
            $lineExtAmount = $this->createElement('cbc:LineExtensionAmount', (string) $line->netTotal());
            $lineExtAmount->setAttribute('currencyID', $invoice->currency);
            $lineElement->appendChild($lineExtAmount);
            
            // Item
            $item = $this->createElement('cac:Item');
            
            $name = $this->createElement('cbc:Name', $line->name);
            $item->appendChild($name);
            
            // Classified tax category
            $taxCategory = $this->createElement('cac:ClassifiedTaxCategory');
            $taxId = $this->createElement('cbc:ID', 'S'); // Standard rate
            $taxCategory->appendChild($taxId);
            
            $taxPercent = $this->createElement('cbc:Percent', (string) $line->taxRate);
            $taxCategory->appendChild($taxPercent);
            
            $taxScheme = $this->createElement('cac:TaxScheme');
            $taxSchemeId = $this->createElement('cbc:ID', 'VAT');
            $taxScheme->appendChild($taxSchemeId);
            $taxCategory->appendChild($taxScheme);
            
            $item->appendChild($taxCategory);
            $lineElement->appendChild($item);
            
            // Price
            $price = $this->createElement('cac:Price');
            $priceAmount = $this->createElement('cbc:PriceAmount', (string) $line->unitPrice);
            $priceAmount->setAttribute('currencyID', $invoice->currency);
            $price->appendChild($priceAmount);
            
            // Allowance on price (line discount)
            if ($line->discount && $line->discount > 0) {
                $priceAllowance = $this->createElement('cac:AllowanceCharge');
                $chargeIndicator = $this->createElement('cbc:ChargeIndicator', 'false');
                $priceAllowance->appendChild($chargeIndicator);
                
                $chargeAmount = $this->createElement('cbc:Amount', (string) $line->discount);
                $chargeAmount->setAttribute('currencyID', $invoice->currency);
                $priceAllowance->appendChild($chargeAmount);
                
                $baseAmount = $this->createElement('cbc:BaseAmount', (string) $line->unitPrice);
                $baseAmount->setAttribute('currencyID', $invoice->currency);
                $priceAllowance->appendChild($baseAmount);
                
                $price->appendChild($priceAllowance);
            }
            
            $lineElement->appendChild($price);
            
            $this->root->appendChild($lineElement);
        }
    }

    /**
     * Create XML element with optional text content
     */
    private function createElement(string $name, ?string $value = null): \DOMElement
    {
        $element = $this->doc->createElement($name);
        
        if ($value !== null) {
            $element->textContent = $value;
        }
        
        return $element;
    }

    /**
     * Canonicalize XML for hashing
     */
    public function canonicalize(string $xml): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        
        return $doc->C14N(true, false);
    }

    /**
     * Calculate invoice hash (SHA-256 of canonicalized XML)
 */
    public function calculateHash(string $xml): string
    {
        $canonicalized = $this->canonicalize($xml);
        return base64_encode(hash('sha256', $canonicalized, true));
    }
}
