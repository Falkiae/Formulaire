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
 * Personnalisation des notifications email/SMS : texte, délai et
 * activation/désactivation par canal, pour chaque événement déclenché par
 * NotificationService. Le moteur (notification_events/notification_templates)
 * existe déjà et est déjà DB-piloté — cet écran n'ajoute qu'une interface
 * d'édition, aucune nouvelle logique de déclenchement.
 */
final class NotificationController
{
    /** Variables disponibles dans les corps de message (NotificationService::context()). */
    public const VARIABLES = [
        'booking.reference', 'booking.total', 'booking.manage_url',
        'customer.first_name', 'customer.last_name', 'customer.email', 'customer.phone',
        'job.date', 'job.arrival_from',
        'technician.first_name',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
    ) {
    }

    /**
     * GET /admin/notifications — liste des événements, chacun avec ses
     * templates par canal.
     */
    public function index(Request $request): Response
    {
        $events = $this->db->select('SELECT * FROM notification_events ORDER BY id');
        foreach ($events as &$event) {
            $event['templates'] = $this->db->select(
                'SELECT * FROM notification_templates WHERE event_id = :e ORDER BY channel',
                ['e' => (int) $event['id']],
            );
        }
        unset($event);

        return $this->view->render('admin/notifications/index', [
            'csrf' => $this->csrf->field(),
            'events' => $events,
            'variables' => self::VARIABLES,
            'flash' => $this->session->pullFlash('notif_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/notifications/{id} — met à jour l'événement (actif/inactif)
     * et chacun de ses templates par canal (sujet/corps/délai/actif).
     */
    public function update(Request $request): Response
    {
        $eventId = (int) $request->attribute('id');
        $event = $this->db->selectOne('SELECT id FROM notification_events WHERE id = :id', ['id' => $eventId]);
        if ($event === null) {
            $this->session->flash('notif_ok', 'Événement introuvable.');

            return Response::redirect('/admin/notifications');
        }

        $this->db->run(
            'UPDATE notification_events SET is_active = :a WHERE id = :id',
            ['a' => $request->bool('event_active') ? 1 : 0, 'id' => $eventId],
        );

        $templates = $this->db->select('SELECT id, channel FROM notification_templates WHERE event_id = :e', ['e' => $eventId]);
        foreach ($templates as $tpl) {
            $tplId = (int) $tpl['id'];
            $prefix = 'tpl_' . $tplId . '_';
            $this->db->run(
                'UPDATE notification_templates SET subject = :subject, body = :body, offset_minutes = :offset, is_active = :active WHERE id = :id',
                [
                    'subject' => $tpl['channel'] === 'email' ? $request->string($prefix . 'subject') : null,
                    'body' => $request->string($prefix . 'body'),
                    'offset' => $request->int($prefix . 'offset'),
                    'active' => $request->bool($prefix . 'active') ? 1 : 0,
                    'id' => $tplId,
                ],
            );
        }

        $this->session->flash('notif_ok', 'Notification enregistrée.');

        return Response::redirect('/admin/notifications');
    }
}
