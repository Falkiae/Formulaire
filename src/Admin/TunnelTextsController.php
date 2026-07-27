<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\TunnelTexts;

/**
 * Textes fixes des 9 étapes du tunnel public — édition libre, effet immédiat
 * (pas de cycle brouillon/publication, contrairement aux questions dynamiques
 * de /admin/formulaire/{id}). Écriture directe sur `settings` (même pattern
 * que SettingsController), une seule clé JSON.
 */
final class TunnelTextsController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
    ) {
    }

    /**
     * GET /admin/formulaire/textes
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/form/texts', [
            'csrf' => $this->csrf->field(),
            'texts' => TunnelTexts::resolve($this->db),
            'flash' => $this->session->pullFlash('tunnel_texts_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/formulaire/textes
     */
    public function update(Request $request): Response
    {
        $result = [];
        foreach (TunnelTexts::DEFAULTS as $step => $keys) {
            foreach (array_keys($keys) as $key) {
                $value = trim($request->string("{$step}__{$key}"));
                if ($value !== '') {
                    $result[$step][$key] = $value;
                }
            }
        }

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $exists = $this->db->scalar("SELECT `key` FROM settings WHERE `key` = 'widget.tunnel_texts'");
        if ($exists === null) {
            $this->db->insert('settings', [
                'key' => 'widget.tunnel_texts',
                'value' => $json,
                'value_type' => 'json',
                'group' => 'widget',
                'label' => 'Textes des étapes du tunnel de réservation',
            ]);
        } else {
            $this->db->run("UPDATE settings SET `value` = :v WHERE `key` = 'widget.tunnel_texts'", ['v' => $json]);
        }

        $this->session->flash('tunnel_texts_ok', 'Textes enregistrés.');

        return Response::redirect('/admin/formulaire/textes');
    }
}
