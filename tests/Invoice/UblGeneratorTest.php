<?php

declare(strict_types=1);

namespace Keepnew\Tests\Invoice;

use Keepnew\Invoice\UblGenerator;
use PHPUnit\Framework\TestCase;

final class UblGeneratorTest extends TestCase
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private \DOMXPath $xpath;
    private string $xml;

    protected function setUp(): void
    {
        $this->xml = (new UblGenerator())->generate(
            ['number' => '2026-000001', 'issued_at' => '2026-07-21 10:00:00', 'subtotal_cents' => 11900, 'vat_cents' => 2499, 'total_cents' => 14399, 'vat_rate_bp' => 2100],
            ['name' => 'Keepnew SRL', 'vat' => 'BE1009875116'],
            ['name' => 'ACME SA', 'vat' => 'BE0123456789'],
            [['description' => 'Nettoyage canapé', 'quantity' => 1, 'unit_price_cents' => 11900, 'line_total_cents' => 11900]],
        );

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($this->xml), 'XML mal formé');

        $this->xpath = new \DOMXPath($doc);
        $this->xpath->registerNamespace('cbc', self::CBC);
        $this->xpath->registerNamespace('cac', self::CAC);
    }

    /**
     * Interroge le document comme le ferait un point d'accès Peppol : par
     * XPath, pas par sous-chaîne. L'ancienne version de ce test cherchait le
     * texte brut « <cbc:PayableAmount currencyID="EUR">143.99 » et tombait en
     * échec dès que le sérialiseur ajoutait un xmlns sur l'élément — alors que
     * les montants étaient justes. Un test de facturation ne doit pas être
     * sensible à la mise en forme du XML.
     */
    private function value(string $expression): ?string
    {
        $found = $this->xpath->query($expression);
        self::assertNotFalse($found, "XPath invalide : {$expression}");

        return $found->length > 0 ? $found->item(0)->nodeValue : null;
    }

    public function testCarriesThePeppolIdentifiers(): void
    {
        self::assertStringContainsString('urn:cen.eu:en16931', (string) $this->value('//cbc:CustomizationID'));
        self::assertStringContainsString('peppol.eu', (string) $this->value('//cbc:ProfileID'));
        self::assertSame('2026-000001', $this->value('//cbc:ID'));
        self::assertSame('380', $this->value('//cbc:InvoiceTypeCode'));
        self::assertSame('EUR', $this->value('//cbc:DocumentCurrencyCode'));
    }

    public function testMonetaryTotalsAreCoherent(): void
    {
        self::assertSame('119.00', $this->value('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        self::assertSame('143.99', $this->value('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        self::assertSame('143.99', $this->value('//cac:LegalMonetaryTotal/cbc:PayableAmount'));
        self::assertSame('119.00', $this->value('//cac:TaxTotal//cbc:TaxableAmount'));
        self::assertSame('21.00', $this->value('//cac:TaxTotal//cbc:Percent'));

        // HTVA + TVA = TVAC : la cohérence qu'un contrôle fiscal vérifierait.
        $ht = (float) $this->value('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount');
        $tvac = (float) $this->value('//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount');
        $vat = (float) $this->value('//cac:TaxTotal/cbc:TaxAmount');
        self::assertEqualsWithDelta($tvac, $ht + $vat, 0.01);
    }

    public function testBothPartiesCarryTheirVatNumber(): void
    {
        self::assertSame('Keepnew SRL', $this->value('//cac:AccountingSupplierParty//cbc:Name'));
        self::assertSame('BE1009875116', $this->value('//cac:AccountingSupplierParty//cbc:CompanyID'));
        self::assertSame('ACME SA', $this->value('//cac:AccountingCustomerParty//cbc:Name'));
        self::assertSame('BE0123456789', $this->value('//cac:AccountingCustomerParty//cbc:CompanyID'));
    }

    public function testEachOrderLineBecomesAnInvoiceLine(): void
    {
        self::assertSame(1, $this->xpath->query('//cac:InvoiceLine')->length);
        self::assertSame('Nettoyage canapé', $this->value('//cac:InvoiceLine//cac:Item/cbc:Name'));
        self::assertSame('119.00', $this->value('//cac:InvoiceLine/cbc:LineExtensionAmount'));
    }

    /**
     * Les trois préfixes sont déclarés une seule fois, sur la racine. Sans ça,
     * chaque élément re-déclarait le sien : document deux fois plus lourd pour
     * un contenu identique.
     */
    public function testNamespacesAreDeclaredOnceOnTheRoot(): void
    {
        self::assertSame(1, substr_count($this->xml, 'xmlns:cbc='));
        self::assertSame(1, substr_count($this->xml, 'xmlns:cac='));
    }
}
