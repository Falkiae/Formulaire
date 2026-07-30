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
 * Réglages généraux : coordonnées de contact, lien CGV, délai de
 * modification/annulation en libre-service pour le client. Écriture directe
 * sur la table `settings` existante (même pattern SQL que InvoiceController/
 * CatalogApiController) — pas de repository dédié pour si peu de champs.
 */
final class SettingsController
{
    /** @var list<string> */
    private const KEYS = [
        'company.phone', 'company.email', 'company.terms_url', 'company.review_url',
        'booking.self_service_deadline_hours', 'company.email_signature_html',
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
        $rows = $this->db->select(
            'SELECT `key`, `value` FROM settings WHERE `key` IN (' . implode(',', array_fill(0, count(self::KEYS), '?')) . ')',
            self::KEYS,
        );
        $values = [];
        foreach ($rows as $r) {
            $values[$r['key']] = $r['value'];
        }

        return $this->view->render('admin/settings/index', [
            'csrf' => $this->csrf->field(),
            'values' => $values,
            'flash' => $this->session->pullFlash('settings_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/reglages
     */
    public function update(Request $request): Response
    {
        $deadline = $request->int('booking_self_service_deadline_hours', 48);
        $this->set('company.phone', $request->string('company_phone'), 'general', 'Téléphone de contact');
        $this->set('company.email', $request->string('company_email'), 'general', 'E-mail de contact');
        $this->set('company.terms_url', $request->string('company_terms_url'), 'general', 'Lien CGV');
        $this->set('company.review_url', $request->string('company_review_url'), 'general', 'Lien public de dépôt d\'avis ({{review.url}})');
        $this->set('booking.self_service_deadline_hours', (string) max(1, $deadline), 'booking', 'Délai (h) de modification/annulation en libre-service client');
        $this->set('company.email_signature_html', $request->string('company_email_signature_html'), 'general', 'Signature HTML ajoutée en fin de chaque email');

        $this->session->flash('settings_ok', 'Réglages enregistrés.');

        return Response::redirect('/admin/reglages');
    }

    private function set(string $key, string $value, string $group, string $label): void
    {
        $exists = $this->db->scalar('SELECT `key` FROM settings WHERE `key` = :k', ['k' => $key]);
        if ($exists === null) {
            $this->db->insert('settings', ['key' => $key, 'value' => $value, 'group' => $group, 'label' => $label]);

            return;
        }
        $this->db->run('UPDATE settings SET `value` = :v WHERE `key` = :k', ['v' => $value, 'k' => $key]);
    }
}
