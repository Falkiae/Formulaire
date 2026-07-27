<?php

declare(strict_types=1);

namespace Keepnew\Admin;

use Keepnew\Core\Request;
use Keepnew\Technician\TechnicianRepository;
use Keepnew\Zone\ZoneRepository;

/**
 * Filtres partagés par les vues Jour (Dispatch), Semaine et Mois du
 * calendrier : type de prestation, territoire (zone ou atelier), technicien.
 * Extrait de CalendarController pour être réutilisé tel quel par
 * DispatchController (Phase AI).
 */
final class ScheduleFilters
{
    /**
     * @return array{mode:string, technician_id:int, zone_id:int, location_id:int}
     */
    public static function parse(Request $request): array
    {
        $mode = $request->string('mode');

        return [
            'mode' => in_array($mode, ['onsite', 'workshop'], true) ? $mode : '',
            'technician_id' => $request->int('tech'),
            // Mutuellement exclusifs côté UI (sidebar "Territoires" à sélection
            // unique) : une zone ne concerne que le domicile, un atelier que
            // le workshop — cf. DispatchService::range().
            'zone_id' => $request->int('zone_id'),
            'location_id' => $request->int('location_id'),
        ];
    }

    /**
     * Sérialise les filtres pour les préserver dans les liens de navigation.
     *
     * @param array{mode:string, technician_id:int, zone_id:int, location_id:int} $filters
     */
    public static function queryString(array $filters): string
    {
        $qs = '';
        if ($filters['mode'] !== '') {
            $qs .= '&mode=' . $filters['mode'];
        }
        if ($filters['technician_id'] > 0) {
            $qs .= '&tech=' . $filters['technician_id'];
        }
        if ($filters['zone_id'] > 0) {
            $qs .= '&zone_id=' . $filters['zone_id'];
        }
        if ($filters['location_id'] > 0) {
            $qs .= '&location_id=' . $filters['location_id'];
        }

        return $qs;
    }

    /**
     * Zones et ateliers actifs pour la sidebar "Territoires" (mélangés dans
     * une seule liste côté vue : une zone ne concerne que le domicile, un
     * atelier que le workshop — cf. DispatchService::range()).
     *
     * @return array{zones:list<array<string,mixed>>, locations:list<array<string,mixed>>}
     */
    public static function territories(ZoneRepository $zones, TechnicianRepository $technicians): array
    {
        return [
            'zones' => array_values(array_filter(
                $zones->all(),
                static fn (array $z): bool => (int) $z['is_active'] === 1,
            )),
            'locations' => $technicians->activeLocations(),
        ];
    }
}
