<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Csrf;
use Keepnew\Core\Request;
use Keepnew\Core\Response;
use Keepnew\Core\Session;
use Keepnew\Core\View;
use Keepnew\Support\Clock;

/**
 * Dispatch : agenda du jour par technicien + réassignation drag & drop.
 */
final class DispatchController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly DispatchService $dispatch,
    ) {
    }

    /**
     * GET /admin/dispatch?date=YYYY-MM-DD
     */
    public function index(Request $request): Response
    {
        $date = $request->string('date') ?: Clock::format(Clock::nowUtc(), 'Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Clock::format(Clock::nowUtc(), 'Y-m-d');
        }
        $data = $this->dispatch->day($date);

        return $this->view->render('admin/dispatch', [
            'csrf_token' => $this->csrf->token(),
            'date' => $date,
            'prev' => (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'),
            'next' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'),
            'technicians' => $data['technicians'],
            'jobs' => $data['jobs'],
            'unassigned' => $data['unassigned'],
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
