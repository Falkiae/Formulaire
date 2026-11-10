<?php

declare(strict_types=1);

namespace Keepnew\Invoice;

/**
 * Génération d'une facture au format UBL BIS 3.0 (Peppol) pour le B2B.
 *
 * Obligatoire en Belgique depuis janvier 2026 pour les factures entre
 * assujettis. Produit un XML conforme aux éléments essentiels de BIS Billing 3.0
 * (Invoice). Pur : ne dépend pas de la base.
 */
final class UblGenerator
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const INV = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /**
     * @param array<string, mixed> $invoice  number, issued_at, subtotal_cents, vat_cents, total_cents, vat_rate_bp
     * @param array<string, mixed> $supplier name, vat, address...
     * @param array<string, mixed> $customer name, vat, email...
     * @param list<array<string, mixed>> $lines
     */
    public function generate(array $invoice, array $supplier, array $customer, array $lines): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Racine chargée depuis une chaîne, et NON construite par
        // createElementNS + setAttributeNS : cette seconde forme n'inscrit pas
        // les préfixes dans la portée du document, si bien que chaque élément
        // enfant re-déclarait son propre xmlns (35 déclarations redondantes
        // dans une facture d'une ligne). Le XML restait valide et les montants
        // justes, mais illisible — et toute assertion textuelle dessus devenait
        // fausse. Déclarés ici, les trois préfixes sont réutilisés tels quels.
        $doc->loadXML(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="%s" xmlns:cbc="%s" xmlns:cac="%s"/>',
            self::INV,
            self::CBC,
            self::CAC,
        ));

        /** @var \DOMElement $root */
        $root = $doc->documentElement;

        $cbc = fn (string $name, string $value): \DOMElement => $doc->createElement('cbc:' . $name, htmlspecialchars($value, ENT_XML1));

        $root->appendChild($cbc('CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0'));
        $root->appendChild($cbc('ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'));
        $root->appendChild($cbc('ID', (string) $invoice['number']));
        $root->appendChild($cbc('IssueDate', substr((string) ($invoice['issued_at'] ?? gmdate('Y-m-d')), 0, 10)));
        $root->appendChild($cbc('InvoiceTypeCode', '380')); // facture commerciale
        $root->appendChild($cbc('DocumentCurrencyCode', 'EUR'));

        $root->appendChild($this->party($doc, 'AccountingSupplierParty', $supplier));
        $root->appendChild($this->party($doc, 'AccountingCustomerParty', $customer));

        // TaxTotal.
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxAmount = $cbc('TaxAmount', $this->money((int) $invoice['vat_cents']));
        $taxAmount->setAttribute('currencyID', 'EUR');
        $taxTotal->appendChild($taxAmount);
        $sub = $doc->createElement('cac:TaxSubtotal');
        $tb = $cbc('TaxableAmount', $this->money((int) $invoice['subtotal_cents']));
        $tb->setAttribute('currencyID', 'EUR');
        $ta = $cbc('TaxAmount', $this->money((int) $invoice['vat_cents']));
        $ta->setAttribute('currencyID', 'EUR');
        $sub->appendChild($tb);
        $sub->appendChild($ta);
        $cat = $doc->createElement('cac:TaxCategory');
        $cat->appendChild($cbc('ID', 'S'));
        $cat->appendChild($cbc('Percent', number_format(((int) $invoice['vat_rate_bp']) / 100, 2, '.', '')));
        $scheme = $doc->createElement('cac:TaxScheme');
        $scheme->appendChild($cbc('ID', 'VAT'));
        $cat->appendChild($scheme);
        $sub->appendChild($cat);
        $taxTotal->appendChild($sub);
        $root->appendChild($taxTotal);

        // LegalMonetaryTotal.
        $mt = $doc->createElement('cac:LegalMonetaryTotal');
        foreach ([
            'LineExtensionAmount' => (int) $invoice['subtotal_cents'],
            'TaxExclusiveAmount' => (int) $invoice['subtotal_cents'],
            'TaxInclusiveAmount' => (int) $invoice['total_cents'],
            'PayableAmount' => (int) $invoice['total_cents'],
        ] as $name => $cents) {
            $amt = $cbc($name, $this->money($cents));
            $amt->setAttribute('currencyID', 'EUR');
            $mt->appendChild($amt);
        }
        $root->appendChild($mt);

        // Lignes.
        $i = 1;
        foreach ($lines as $line) {
            $il = $doc->createElement('cac:InvoiceLine');
            $il->appendChild($cbc('ID', (string) $i++));
            $qty = $cbc('InvoicedQuantity', (string) ((int) ($line['quantity'] ?? 1)));
            $qty->setAttribute('unitCode', 'C62');
            $il->appendChild($qty);
            $lea = $cbc('LineExtensionAmount', $this->money((int) $line['line_total_cents']));
            $lea->setAttribute('currencyID', 'EUR');
            $il->appendChild($lea);
            $item = $doc->createElement('cac:Item');
            $item->appendChild($cbc('Name', (string) ($line['description'] ?? 'Prestation')));
            $il->appendChild($item);
            $price = $doc->createElement('cac:Price');
            $pa = $cbc('PriceAmount', $this->money((int) $line['unit_price_cents']));
            $pa->setAttribute('currencyID', 'EUR');
            $price->appendChild($pa);
            $il->appendChild($price);
            $root->appendChild($il);
        }

        return (string) $doc->saveXML();
    }

    /**
     * @param array<string, mixed> $party
     */
    private function party(\DOMDocument $doc, string $role, array $party): \DOMElement
    {
        $wrap = $doc->createElement('cac:' . $role);
        $p = $doc->createElement('cac:Party');

        $nameWrap = $doc->createElement('cac:PartyName');
        $nameWrap->appendChild($doc->createElement('cbc:Name', htmlspecialchars((string) ($party['name'] ?? ''), ENT_XML1)));
        $p->appendChild($nameWrap);

        if (!empty($party['vat'])) {
            $taxScheme = $doc->createElement('cac:PartyTaxScheme');
            $taxScheme->appendChild($doc->createElement('cbc:CompanyID', htmlspecialchars((string) $party['vat'], ENT_XML1)));
            $ts = $doc->createElement('cac:TaxScheme');
            $ts->appendChild($doc->createElement('cbc:ID', 'VAT'));
            $taxScheme->appendChild($ts);
            $p->appendChild($taxScheme);
        }

        $wrap->appendChild($p);

        return $wrap;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
