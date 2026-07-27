<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Database;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;
use Keepnew\Technician\TechnicianRepository;
use Keepnew\Zone\ZoneRepository;

/**
 * Dispatch : vue Jour du planning (liste de RDV + carte), avec les mêmes
 * filtres territoire/technicien que le calendrier Semaine/Mois. La
 * réassignation d'un technicien se fait désormais depuis le panneau job
 * (sélecteur de créneaux), plus par glisser-déposer sur cette page.
 */
final class DispatchController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly DispatchService $dispatch,
        private readonly ZoneRepository $zones,
        private readonly TechnicianRepository $technicians,
        private readonly Database $db,
    ) {
    }

    /**
     * GET /admin/dispatch?date=YYYY-MM-DD&mode=&tech=&zone_id=&location_id=
     */
    public function index(Request $request): Response
    {
        $date = $request->string('date') ?: Clock::format(Clock::nowUtc(), 'Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Clock::format(Clock::nowUtc(), 'Y-m-d');
        }

        $filters = ScheduleFilters::parse($request);
        $jobs = $this->dispatch->range($date, $date, $filters);
        $unassigned = $this->dispatch->unscheduledJobs();

        // range() exclut déjà les jobs annulés (status NOT IN ('cancelled')).
        $vatRateBp = (int) ($this->db->scalar("SELECT `value` FROM settings WHERE `key` = 'finance.vat_rate_bp'") ?? 2100);
        $totalDurationMin = array_sum(array_map(static fn (array $j): int => $j['duration_min'] ?? 0, $jobs));
        $totalHtCents = array_sum(array_map(static fn (array $j): int => $j['total_ht_cents'], $jobs));
        $totalTvacCents = (int) round($totalHtCents * (10000 + $vatRateBp) / 10000);

        // Itinéraire : arrêts domicile géolocalisés, dans l'ordre chronologique
        // déjà garanti par DispatchService::range() (ORDER BY scheduled_start).
        $stops = array_values(array_filter(
            $jobs,
            static fn (array $j): bool => $j['mode'] === 'onsite' && $j['lat'] !== null && $j['lng'] !== null,
        ));
        $routeUrl = count($stops) >= 2
            ? 'https://www.google.com/maps/dir/' . implode('/', array_map(
                static fn (array $j): string => $j['lat'] . ',' . $j['lng'],
                $stops,
            ))
            : null;

        return $this->view->render('admin/dispatch', [
            'csrf_token' => $this->csrf->token(),
            'date' => $date,
            'prev' => (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'),
            'next' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'),
            'technicians' => $this->dispatch->activeTechnicians(),
            'jobs' => $jobs,
            'unassigned' => $unassigned,
            'filters' => $filters,
            'filter_qs' => ScheduleFilters::queryString($filters),
            'territories' => ScheduleFilters::territories($this->zones, $this->technicians),
            'week_of' => (new \DateTimeImmutable($date))->modify('-' . (((int) (new \DateTimeImmutable($date))->format('N')) - 1) . ' days')->format('Y-m-d'),
            'month_of' => (new \DateTimeImmutable($date))->format('Y-m'),
            'summary' => [
                'job_count' => count($jobs),
                'total_duration_min' => $totalDurationMin,
                'total_tvac_cents' => $totalTvacCents,
                'route_url' => $routeUrl,
            ],
            'user_name' => $this->session->get('user_name'),
        ]);
    }

    /**
     * POST /admin/dispatch/reassign  (JSON) — { job_id, technician_id, start_utc? }
     */
    public function reassign(Request $request): Response
    {
        $result = $this->dispatch->reassign(
            $request->int('job_id'),
            $request->int('technician_id'),
            $request->string('start_utc') ?: null,
            $this->session->userId(),
        );

        $status = $result['ok'] ? 200 : 409;

        return Response::json($result, $status);
    }
}
