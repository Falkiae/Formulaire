<?php

declare(strict_types=1);

namespace Keepnew\Tests\Invoice;

use Keepnew\Invoice\UblGenerator;
use PHPUnit\Framework\TestCase;

final class UblGeneratorTest extends TestCase
{
    public function testProducesValidUblXml(): void
    {
        $xml = (new UblGenerator())->generate(
            ['number' => '2026-000001', 'issued_at' => '2026-07-21 10:00:00', 'subtotal_cents' => 11900, 'vat_cents' => 2499, 'total_cents' => 14399, 'vat_rate_bp' => 2100],
            ['name' => 'Keepnew SRL', 'vat' => 'BE1009875116'],
            ['name' => 'ACME SA', 'vat' => 'BE0123456789'],
            [['description' => 'Nettoyage canapé', 'quantity' => 1, 'unit_price_cents' => 11900, 'line_total_cents' => 11900]],
        );

        // XML bien formé.
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));

        // Éléments essentiels BIS 3.0.
        self::assertStringContainsString('2026-000001', $xml);
        self::assertStringContainsString('BE1009875116', $xml);
        self::assertStringContainsString('<cbc:PayableAmount currencyID="EUR">143.99</cbc:PayableAmount>', $xml);
        self::assertStringContainsString('<cbc:Percent>21.00</cbc:Percent>', $xml);
        self::assertStringContainsString('urn:cen.eu:en16931', $xml);
    }
}
