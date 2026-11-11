<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;

/**
 * Réglages de l'application.
 *
 * La page n'exposait que six champs alors que la table `settings` en compte
 * vingt-neuf : changer le taux de TVA, la remise groupée, le seuil de facture
 * simplifiée ou même la raison sociale qui figure sur les factures imposait
 * d'ouvrir phpMyAdmin. Sans git en production, c'est disproportionné pour un
 * pourcentage de remise.
 *
 * Les champs sont décrits ici une fois (clé, libellé, unité, bornes) et la vue
 * se contente de les rendre : ajouter un réglage, c'est ajouter une ligne.
 *
 * Deux principes :
 *
 * 1. ON N'EXPOSE QUE CE QUI AGIT. Dix clés de la table ne sont lues par aucun
 *    code (`display.timezone`, les quatre `geo.*` autres que la clé ORS,
 *    `booking.default_mode`, `booking.auto_advance`,
 *    `badge.most_requested_min_volume`). Les afficher ferait croire qu'on les
 *    règle — le même piège que le champ « Icône » de la fiche catégorie. Elles
 *    restent en base, invisibles, jusqu'à ce qu'un code les lise.
 * 2. ON PARLE LA LANGUE DU MÉTIER, pas celle de la base : un taux de TVA se
 *    saisit en pour cent et non en points de base, un seuil de facture en
 *    euros et non en centimes. La conversion est faite ici.
 */
final class SettingsController
{
    /**
     * Description des réglages exposés.
     *
     * type : text | url | email | int | money | percent | textarea | secret
     * `min`/`max` bornent les entiers ; `suffix` est l'unité affichée.
     *
     * @var list<array{section:string, key:string, name:string, label:string, type:string, help?:string, min?:int, max?:int, suffix?:string}>
     */
    private const FIELDS = [
        // --- Société (figure sur les factures) ---
        ['section' => 'Société', 'key' => 'company.name', 'name' => 'company_name', 'label' => 'Raison sociale', 'type' => 'text',
            'help' => 'Apparaît sur les factures et dans les e-mails.'],
        ['section' => 'Société', 'key' => 'company.vat', 'name' => 'company_vat', 'label' => 'Numéro de TVA', 'type' => 'text',
            'help' => 'Format belge : BE suivi de 10 chiffres.'],
        ['section' => 'Société', 'key' => 'company.phone', 'name' => 'company_phone', 'label' => 'Téléphone public', 'type' => 'text',
            'help' => 'Affiché au client à la fin du tunnel et dans les e-mails.'],
        ['section' => 'Société', 'key' => 'company.email', 'name' => 'company_email', 'label' => 'E-mail public', 'type' => 'email'],

        // --- Liens montrés au client ---
        ['section' => 'Liens publics', 'key' => 'company.terms_url', 'name' => 'company_terms_url', 'label' => 'Conditions générales', 'type' => 'url',
            'help' => 'Sans ce lien, la case de consentement du tunnel ne renvoie vers rien.'],
        ['section' => 'Liens publics', 'key' => 'company.review_url', 'name' => 'company_review_url', 'label' => 'Page de dépôt d\'avis', 'type' => 'url',
            'help' => 'Utilisée par le message « demande d\'avis » ({{review.url}}).'],

        // --- E-mails ---
        ['section' => 'E-mails', 'key' => 'company.email_signature_html', 'name' => 'company_email_signature_html', 'label' => 'Signature (HTML)', 'type' => 'textarea',
            'help' => 'Ajoutée à la fin de chaque e-mail envoyé au client.'],

        // --- Finances ---
        ['section' => 'Finances', 'key' => 'finance.vat_rate_bp', 'name' => 'finance_vat_rate', 'label' => 'Taux de TVA', 'type' => 'percent', 'suffix' => '%',
            'help' => 'Appliqué à tout le catalogue. 21 % en Belgique pour ces prestations.'],
        ['section' => 'Finances', 'key' => 'finance.simplified_invoice_max_cents', 'name' => 'finance_simplified_invoice_max', 'label' => 'Seuil de facture simplifiée', 'type' => 'money', 'suffix' => '€ TVAC',
            'help' => 'En dessous de ce total, la facture émise est simplifiée.'],
        ['section' => 'Finances', 'key' => 'invoice.auto_delay_hours', 'name' => 'invoice_auto_delay_hours', 'label' => 'Délai avant facturation automatique', 'type' => 'int', 'min' => 0, 'max' => 720, 'suffix' => 'heures',
            'help' => 'Temps laissé à l\'équipe pour corriger un prix après l\'intervention, avant que le cron ne facture.'],

        // --- Tarification ---
        ['section' => 'Tarification', 'key' => 'discount.multi_item_percent_bp', 'name' => 'discount_multi_item_percent', 'label' => 'Remise groupée', 'type' => 'percent', 'suffix' => '%',
            'help' => 'Appliquée quand le panier atteint le nombre de prestations ci-dessous.'],
        ['section' => 'Tarification', 'key' => 'discount.multi_item_min_items', 'name' => 'discount_multi_item_min_items', 'label' => 'À partir de', 'type' => 'int', 'min' => 2, 'max' => 20, 'suffix' => 'prestations'],

        // --- Réservation ---
        ['section' => 'Réservation', 'key' => 'booking.self_service_deadline_hours', 'name' => 'booking_self_service_deadline_hours', 'label' => 'Modification / annulation par le client', 'type' => 'int', 'min' => 1, 'max' => 720, 'suffix' => 'heures avant le RDV',
            'help' => 'Passé ce délai, le client doit appeler.'],
        ['section' => 'Réservation', 'key' => 'booking.hold_minutes', 'name' => 'booking_hold_minutes', 'label' => 'Blocage d\'un créneau pendant la réservation', 'type' => 'int', 'min' => 1, 'max' => 120, 'suffix' => 'minutes',
            'help' => 'Durée pendant laquelle un créneau en cours de réservation reste réservé au client.'],
        ['section' => 'Réservation', 'key' => 'cart.expiry_days', 'name' => 'cart_expiry_days', 'label' => 'Expiration d\'un panier abandonné', 'type' => 'int', 'min' => 1, 'max' => 90, 'suffix' => 'jours'],

        // --- Disponibilité ---
        ['section' => 'Disponibilité', 'key' => 'availability.horizon_days', 'name' => 'availability_horizon_days', 'label' => 'Horizon de réservation', 'type' => 'int', 'min' => 7, 'max' => 365, 'suffix' => 'jours',
            'help' => 'Jusqu\'où le client peut réserver. C\'est aussi jusqu\'où le calendrier du tunnel le laisse avancer.'],
        ['section' => 'Disponibilité', 'key' => 'availability.min_lead_hours', 'name' => 'availability_min_lead_hours', 'label' => 'Délai minimum avant intervention', 'type' => 'int', 'min' => 0, 'max' => 720, 'suffix' => 'heures',
            'help' => 'Aucun créneau n\'est proposé plus tôt que ce délai.'],
        ['section' => 'Disponibilité', 'key' => 'availability.slot_step_min', 'name' => 'availability_slot_step_min', 'label' => 'Pas des créneaux proposés', 'type' => 'int', 'min' => 5, 'max' => 240, 'suffix' => 'minutes',
            'help' => '30 = des créneaux à 9h00, 9h30, 10h00…'],
        ['section' => 'Disponibilité', 'key' => 'availability.setup_buffer_min', 'name' => 'availability_setup_buffer_min', 'label' => 'Marge installation / rangement', 'type' => 'int', 'min' => 0, 'max' => 240, 'suffix' => 'minutes par RDV'],
        ['section' => 'Disponibilité', 'key' => 'availability.max_job_duration_min', 'name' => 'availability_max_job_duration_min', 'label' => 'Durée maximale d\'un rendez-vous', 'type' => 'int', 'min' => 30, 'max' => 1440, 'suffix' => 'minutes',
            'help' => 'Au-delà, la commande est fractionnée en plusieurs rendez-vous.'],

        // --- Intégrations ---
        ['section' => 'Intégrations', 'key' => 'geo.ors_api_key', 'name' => 'geo_ors_api_key', 'label' => 'Clé API OpenRouteService', 'type' => 'secret',
            'help' => 'Calcul des trajets entre deux rendez-vous. Laisser vide conserve la clé actuelle.'],
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
    ) {
    }

    /**
     * GET /admin/reglages
     */
    public function index(Request $request): Response
    {
        $values = $this->currentValues();

        // Regroupe pour la vue en conservant l'ordre de déclaration.
        $sections = [];
        foreach (self::FIELDS as $f) {
            $f['value'] = $this->toDisplay($f, $values[$f['key']] ?? '');
            $f['is_set'] = ($values[$f['key']] ?? '') !== '';
            $sections[$f['section']][] = $f;
        }

        return $this->view->render('admin/settings/index', [
            'csrf' => $this->csrf->field(),
            'sections' => $sections,
            'flash' => $this->session->pullFlash('settings_ok'),
            'error' => $this->session->pullFlash('settings_error'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/reglages
     */
    public function update(Request $request): Response
    {
        $errors = [];
        $toWrite = [];

        foreach (self::FIELDS as $f) {
            $raw = trim($request->string($f['name']));

            // Un secret laissé vide n'efface pas la clé en place : sinon, tout
            // enregistrement de la page ferait perdre la clé API.
            if ($f['type'] === 'secret' && $raw === '') {
                continue;
            }

            $stored = $this->toStorage($f, $raw, $errors);
            if ($stored === null) {
                continue;
            }
            $toWrite[$f['key']] = $stored;
        }

        if ($errors !== []) {
            $this->session->flash('settings_error', implode(' ', $errors));

            return Response::redirect('/admin/reglages');
        }

        foreach ($toWrite as $key => $value) {
            $this->set($key, $value);
        }

        $this->session->flash('settings_ok', 'Réglages enregistrés.');

        return Response::redirect('/admin/reglages');
    }

    /**
     * @return array<string, string>
     */
    private function currentValues(): array
    {
        $keys = array_column(self::FIELDS, 'key');
        $rows = $this->db->select(
            'SELECT `key`, `value` FROM settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')',
            $keys,
        );
        $values = [];
        foreach ($rows as $r) {
            $values[$r['key']] = (string) $r['value'];
        }

        return $values;
    }

    /**
     * Valeur stockée → valeur affichée dans le champ.
     *
     * @param array<string, mixed> $f
     */
    private function toDisplay(array $f, string $stored): string
    {
        return match ($f['type']) {
            // Points de base → pour cent : 2100 devient « 21 », 1050 « 10,5 ».
            'percent' => $stored === '' ? '' : rtrim(rtrim(number_format((int) $stored / 100, 2, ',', ''), '0'), ','),
            'money' => $stored === '' ? '' : number_format((int) $stored / 100, 2, ',', ''),
            // Un secret n'est jamais renvoyé au navigateur.
            'secret' => '',
            default => $stored,
        };
    }

    /**
     * Valeur saisie → valeur stockée. Renvoie null si le champ est à ignorer.
     *
     * @param array<string, mixed> $f
     * @param list<string> $errors
     */
    private function toStorage(array $f, string $raw, array &$errors): ?string
    {
        $num = static fn (string $v): float => (float) str_replace([' ', ','], ['', '.'], $v);

        switch ($f['type']) {
            case 'percent':
                $pct = $num($raw);
                if ($pct < 0 || $pct > 100) {
                    $errors[] = "« {$f['label']} » doit être compris entre 0 et 100 %.";

                    return null;
                }

                return (string) (int) round($pct * 100);

            case 'money':
                $eur = $num($raw);
                if ($eur < 0) {
                    $errors[] = "« {$f['label']} » ne peut pas être négatif.";

                    return null;
                }

                return (string) (int) round($eur * 100);

            case 'int':
                $n = (int) $num($raw);
                $min = $f['min'] ?? 0;
                $max = $f['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    $errors[] = "« {$f['label']} » doit être compris entre {$min} et {$max}.";

                    return null;
                }

                return (string) $n;

            case 'url':
                if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_URL)) {
                    $errors[] = "« {$f['label']} » n'est pas une adresse valide.";

                    return null;
                }

                return $raw;

            case 'email':
                if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "« {$f['label']} » n'est pas une adresse e-mail valide.";

                    return null;
                }

                return $raw;

            default:
                return $raw;
        }
    }

    private function set(string $key, string $value): void
    {
        $exists = $this->db->scalar('SELECT `key` FROM settings WHERE `key` = :k', ['k' => $key]);
        if ($exists === null) {
            $label = '';
            foreach (self::FIELDS as $f) {
                if ($f['key'] === $key) {
                    $label = $f['label'];
                }
            }
            $this->db->insert('settings', ['key' => $key, 'value' => $value, 'group' => 'general', 'label' => $label]);

            return;
        }
        $this->db->run('UPDATE settings SET `value` = :v WHERE `key` = :k', ['v' => $value, 'k' => $key]);
    }
}
