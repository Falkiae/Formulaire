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
 * Ateliers : adresse, horaires, postes de travail, fermetures exceptionnelles.
 */
final class LocationController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Database $db,
    ) {
    }

    public function index(Request $request): Response
    {
        $locations = $this->db->select('SELECT * FROM locations ORDER BY sort_order');
        foreach ($locations as &$loc) {
            $loc['bays'] = $this->db->select('SELECT * FROM workshop_bays WHERE location_id = :id ORDER BY sort_order', ['id' => (int) $loc['id']]);
            $loc['closures'] = $this->db->select('SELECT * FROM location_closures WHERE location_id = :id ORDER BY starts_at', ['id' => (int) $loc['id']]);
        }

        return $this->view->render('admin/locations', [
            'csrf' => $this->csrf->field(),
            'locations' => $locations,
            'user_name' => $this->session->get('user_name'),
            'flash' => $this->session->pullFlash('loc_ok'),
        ]);
    }

    /**
     * POST /admin/ateliers/{id}/poste — ajoute un poste de travail.
     */
    public function addBay(Request $request): Response
    {
        $locationId = (int) $request->attribute('id');
        $name = $request->string('name');
        if ($name !== '') {
            $order = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order),-1)+1 FROM workshop_bays WHERE location_id = :l', ['l' => $locationId]);
            $this->db->insert('workshop_bays', ['location_id' => $locationId, 'name' => $name, 'is_active' => 1, 'sort_order' => $order]);
            $this->session->flash('loc_ok', 'Poste ajouté.');
        }

        return Response::redirect('/admin/ateliers');
    }

    /**
     * POST /admin/ateliers/{id}/fermeture — ajoute une fermeture exceptionnelle.
     */
    public function addClosure(Request $request): Response
    {
        $locationId = (int) $request->attribute('id');
        $from = $request->string('from');
        $to = $request->string('to');
        if ($from !== '' && $to !== '') {
            $this->db->insert('location_closures', [
                'location_id' => $locationId,
                'starts_at' => \Keepnew\Availability\ScheduleBuilder::windowForDate($from, '00:00', '00:01')->start->format('Y-m-d 00:00:00'),
                'ends_at' => \Keepnew\Availability\ScheduleBuilder::windowForDate($to, '23:59', '23:59')->start->format('Y-m-d 23:59:59'),
                'reason' => $request->string('reason') ?: null,
            ]);
            $this->session->flash('loc_ok', 'Fermeture enregistrée.');
        }

        return Response::redirect('/admin/ateliers');
    }
}
