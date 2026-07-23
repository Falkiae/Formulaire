<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;

/**
 * Calendrier des prestations : vues mensuelle et hebdomadaire, en lecture,
 * avec filtres atelier/domicile et par technicien. Complète le dispatch
 * (agenda du jour) pour la vue d'ensemble.
 *
 * Tout le raisonnement de dates est fait en heure belge (concern d'affichage) ;
 * DispatchService::range() convertit les bornes en UTC pour la requête.
 */
final class CalendarController
{
    private const MONTHS_FR = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];
    private const DAYS_FR = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly DispatchService $dispatch,
    ) {
    }

    /**
     * GET /admin/calendrier?date=YYYY-MM — vue mensuelle.
     */
    public function month(Request $request): Response
    {
        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $param = $request->string('date');
        $anchor = preg_match('/^\d{4}-\d{2}$/', $param) === 1
            ? new \DateTimeImmutable($param . '-01', $tz)
            : (new \DateTimeImmutable('now', $tz))->modify('first day of this month');
        $anchor = $anchor->setTime(0, 0);

        $firstOfMonth = $anchor->modify('first day of this month');
        $lastOfMonth = $anchor->modify('last day of this month');

        // Grille : du lundi précédant (ou égal) le 1er, au dimanche suivant (ou égal) la fin.
        $gridStart = $firstOfMonth->modify('-' . (((int) $firstOfMonth->format('N')) - 1) . ' days');
        $gridEnd = $lastOfMonth->modify('+' . (7 - (int) $lastOfMonth->format('N')) . ' days');

        $filters = $this->filters($request);
        $jobs = $this->dispatch->range($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'), $filters);
        $byDate = $this->groupByDate($jobs);

        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $month = (int) $anchor->format('n');

        // Construit les semaines (lignes de 7 jours).
        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $cursor->format('Y-m-d');
                $week[] = [
                    'date' => $d,
                    'day' => (int) $cursor->format('j'),
                    'in_month' => (int) $cursor->format('n') === $month,
                    'is_today' => $d === $today,
                    'jobs' => $byDate[$d] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $this->view->render('admin/calendar/month', [
            'weeks' => $weeks,
            'day_labels' => self::DAYS_FR,
            'label' => self::MONTHS_FR[$month] . ' ' . $anchor->format('Y'),
            'current' => $anchor->format('Y-m'),
            'prev' => $anchor->modify('-1 month')->format('Y-m'),
            'next' => $anchor->modify('+1 month')->format('Y-m'),
            'week_of' => $firstOfMonth->format('Y-m-d'),
            'filters' => $filters,
            'filter_qs' => $this->filterQuery($filters),
            'technicians' => $this->dispatch->activeTechnicians(),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * GET /admin/calendrier/semaine?date=YYYY-MM-DD — vue hebdomadaire.
     */
    public function week(Request $request): Response
    {
        $tz = new \DateTimeZone(Clock::DISPLAY_TZ);
        $param = $request->string('date');
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $param) === 1
            ? new \DateTimeImmutable($param, $tz)
            : new \DateTimeImmutable('now', $tz);
        $anchor = $anchor->setTime(0, 0);

        $monday = $anchor->modify('-' . (((int) $anchor->format('N')) - 1) . ' days');
        $sunday = $monday->modify('+6 days');

        $filters = $this->filters($request);
        $jobs = $this->dispatch->range($monday->format('Y-m-d'), $sunday->format('Y-m-d'), $filters);
        $byDate = $this->groupByDate($jobs);

        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $days = [];
        $cursor = $monday;
        for ($i = 0; $i < 7; $i++) {
            $d = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $d,
                'label' => self::DAYS_FR[$i] . ' ' . $cursor->format('j') . '/' . $cursor->format('n'),
                'is_today' => $d === $today,
                'jobs' => $byDate[$d] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $this->view->render('admin/calendar/week', [
            'days' => $days,
            'label' => 'Semaine du ' . $monday->format('j') . ' ' . self::MONTHS_FR[(int) $monday->format('n')] . ' ' . $monday->format('Y'),
            'current' => $monday->format('Y-m-d'),
            'prev' => $monday->modify('-7 days')->format('Y-m-d'),
            'next' => $monday->modify('+7 days')->format('Y-m-d'),
            'month_of' => $monday->format('Y-m'),
            'filters' => $filters,
            'filter_qs' => $this->filterQuery($filters),
            'technicians' => $this->dispatch->activeTechnicians(),
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * @return array{mode:string, technician_id:int}
     */
    private function filters(Request $request): array
    {
        $mode = $request->string('mode');

        return [
            'mode' => in_array($mode, ['onsite', 'workshop'], true) ? $mode : '',
            'technician_id' => $request->int('tech'),
        ];
    }

    /**
     * Sérialise les filtres pour les préserver dans les liens de navigation.
     *
     * @param array{mode:string, technician_id:int} $filters
     */
    private function filterQuery(array $filters): string
    {
        $qs = '';
        if ($filters['mode'] !== '') {
            $qs .= '&mode=' . $filters['mode'];
        }
        if ($filters['technician_id'] > 0) {
            $qs .= '&tech=' . $filters['technician_id'];
        }

        return $qs;
    }

    /**
     * @param list<array<string,mixed>> $jobs
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupByDate(array $jobs): array
    {
        $byDate = [];
        foreach ($jobs as $job) {
            $date = $job['date_local'];
            if ($date === null) {
                continue;
            }
            $byDate[$date][] = $job;
        }

        return $byDate;
    }
}
