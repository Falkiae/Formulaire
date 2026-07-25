<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Exception\NotFoundException;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Technician\TechnicianRepository;

/**
 * Administration des techniciens : fiche, compétences, disponibilités
 * récurrentes et absences. Alimente les tables lues par le moteur de
 * disponibilité (src/Availability).
 */
final class TechnicianController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly TechnicianRepository $techs,
    ) {
    }

    /**
     * GET /admin/techniciens — liste.
     */
    public function index(Request $request): Response
    {
        return $this->view->render('admin/technicians/index', [
            'technicians' => $this->techs->all(),
            'flash' => $this->session->pullFlash('tech_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * GET /admin/techniciens/nouveau — formulaire de création.
     */
    public function createForm(Request $request): Response
    {
        return $this->renderForm(null);
    }

    /**
     * POST /admin/techniciens — création.
     */
    public function create(Request $request): Response
    {
        if ($request->string('first_name') === '' || $request->string('last_name') === '') {
            $this->session->flash('tech_error', 'Prénom et nom sont requis.');

            return Response::redirect('/admin/techniciens/nouveau');
        }

        $id = $this->techs->create($this->fromRequest($request));
        $this->session->flash('tech_ok', 'Technicien créé. Complétez ses compétences et disponibilités.');

        return Response::redirect('/admin/techniciens/' . $id);
    }

    /**
     * GET /admin/techniciens/{id} — fiche éditable + sous-ressources.
     */
    public function edit(Request $request): Response
    {
        $tech = $this->requireTech($request);

        return $this->renderForm($tech);
    }

    /**
     * POST /admin/techniciens/{id} — mise à jour de la fiche.
     */
    public function update(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $this->techs->update((int) $tech['id'], $this->fromRequest($request));
        $this->session->flash('tech_ok', 'Fiche technicien enregistrée.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/supprimer — supprime si jamais assigné à
     * un job, sinon désactive pour préserver l'historique.
     */
    public function delete(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $result = $this->techs->deleteOrDeactivateTechnician((int) $tech['id']);
        $this->session->flash(
            'tech_ok',
            $result === 'deleted' ? 'Technicien supprimé.' : 'Technicien désactivé (des rendez-vous lui sont associés).',
        );

        return Response::redirect('/admin/techniciens');
    }

    /**
     * POST /admin/techniciens/{id}/competences — synchronise les skills.
     */
    public function syncSkills(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $skillIds = array_map('intval', $request->array('skills'));
        $this->techs->syncSkills((int) $tech['id'], $skillIds);
        $this->session->flash('tech_ok', 'Compétences mises à jour.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/disponibilite — ajoute une plage récurrente.
     */
    public function addAvailability(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $weekday = $request->int('weekday');
        $start = $request->string('start_time');
        $end = $request->string('end_time');

        if ($weekday < 0 || $weekday > 6 || $start === '' || $end === '' || $start >= $end) {
            $this->session->flash('tech_ok', 'Plage invalide : vérifiez le jour et l\'ordre des heures.');

            return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
        }

        $this->techs->addAvailability((int) $tech['id'], [
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'location_id' => $request->int('location_id'),
            'valid_from' => $request->string('valid_from'),
            'valid_until' => $request->string('valid_until'),
        ]);
        $this->session->flash('tech_ok', 'Disponibilité ajoutée.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/disponibilite/{availId}/supprimer
     */
    public function deleteAvailability(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $this->techs->deleteAvailability((int) $request->attribute('availId'), (int) $tech['id']);
        $this->session->flash('tech_ok', 'Disponibilité supprimée.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/absence — ajoute un congé (saisi en heure belge).
     */
    public function addTimeOff(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $start = $request->string('starts_at');
        $end = $request->string('ends_at');

        if ($start === '' || $end === '' || $start >= $end) {
            $this->session->flash('tech_ok', 'Absence invalide : la fin doit suivre le début.');

            return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
        }

        // Les inputs datetime-local sont au format "Y-m-dTH:i" — on remplace le T.
        $this->techs->addTimeOff(
            (int) $tech['id'],
            str_replace('T', ' ', $start),
            str_replace('T', ' ', $end),
            $request->string('reason'),
        );
        $this->session->flash('tech_ok', 'Absence enregistrée.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/absence/{offId}/supprimer
     */
    public function deleteTimeOff(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $this->techs->deleteTimeOff((int) $request->attribute('offId'), (int) $tech['id']);
        $this->session->flash('tech_ok', 'Absence supprimée.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    /**
     * POST /admin/techniciens/{id}/zones — synchronise les zones couvertes.
     */
    public function syncZones(Request $request): Response
    {
        $tech = $this->requireTech($request);
        $zoneIds = array_map('intval', $request->array('zones'));
        $this->techs->syncZones((int) $tech['id'], $zoneIds);
        $this->session->flash('tech_ok', 'Zones mises à jour.');

        return Response::redirect('/admin/techniciens/' . (int) $tech['id']);
    }

    // --- Catalogue des compétences (skills) ------------------------------------

    /**
     * GET /admin/competences — catalogue des compétences.
     */
    public function skillsIndex(Request $request): Response
    {
        return $this->view->render('admin/skills', [
            'csrf' => $this->csrf->field(),
            'skills' => $this->techs->allSkills(),
            'flash' => $this->session->pullFlash('skill_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/competences — création d'une compétence.
     */
    public function createSkill(Request $request): Response
    {
        $code = $request->string('code');
        $label = $request->string('label');
        if ($code === '' || $label === '') {
            $this->session->flash('skill_ok', 'Code et libellé requis.');

            return Response::redirect('/admin/competences');
        }

        $this->techs->createSkill($code, $label);
        $this->session->flash('skill_ok', 'Compétence créée.');

        return Response::redirect('/admin/competences');
    }

    /**
     * POST /admin/competences/{id} — mise à jour d'une compétence.
     */
    public function updateSkill(Request $request): Response
    {
        $this->techs->updateSkill(
            (int) $request->attribute('id'),
            $request->string('code'),
            $request->string('label'),
        );
        $this->session->flash('skill_ok', 'Compétence enregistrée.');

        return Response::redirect('/admin/competences');
    }

    /**
     * POST /admin/competences/{id}/supprimer — suppression (bloquée si
     * utilisée par un technicien ou une prestation).
     */
    public function deleteSkill(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $deleted = $this->techs->deleteSkill($id);
        $this->session->flash(
            'skill_ok',
            $deleted ? 'Compétence supprimée.' : 'Compétence utilisée par des techniciens ou prestations, suppression impossible.',
        );

        return Response::redirect('/admin/competences');
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function requireTech(Request $request): array
    {
        $tech = $this->techs->find((int) $request->attribute('id'));
        if ($tech === null) {
            throw new NotFoundException('Technicien introuvable.');
        }

        return $tech;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromRequest(Request $request): array
    {
        return [
            'user_id' => $request->int('user_id'),
            'first_name' => $request->string('first_name'),
            'last_name' => $request->string('last_name'),
            'phone' => $request->string('phone'),
            'email' => $request->string('email'),
            'home_lat' => $request->string('home_lat'),
            'home_lng' => $request->string('home_lng'),
            'home_postal' => $request->string('home_postal'),
            'max_jobs_per_day' => $request->int('max_jobs_per_day', 6),
            'is_active' => $request->bool('is_active'),
        ];
    }

    /**
     * @param array<string, mixed>|null $tech
     */
    private function renderForm(?array $tech): Response
    {
        $techId = $tech !== null ? (int) $tech['id'] : 0;

        return $this->view->render('admin/technicians/edit', [
            'csrf' => $this->csrf->field(),
            'tech' => $tech,
            'skills' => $this->techs->allSkills(),
            'tech_skill_ids' => $techId > 0 ? $this->techs->skillIdsFor($techId) : [],
            'zones' => $this->techs->activeZones(),
            'tech_zone_ids' => $techId > 0 ? $this->techs->zoneIdsFor($techId) : [],
            'availability' => $techId > 0 ? $this->techs->availabilityFor($techId) : [],
            'time_off' => $techId > 0 ? $this->techs->timeOffFor($techId) : [],
            'locations' => $this->techs->activeLocations(),
            'linkable_users' => $this->techs->linkableUsers(),
            'error' => $this->session->pullFlash('tech_error'),
            'flash' => $this->session->pullFlash('tech_ok'),
            'user_name' => $this->session->get('user_name'),
        ]);
    }
}
