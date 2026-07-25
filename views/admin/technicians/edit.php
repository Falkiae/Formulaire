<?php
/**
 * Vue : fiche technicien (création ou édition + sous-ressources).
 * $data['tech'] === null → création (seule la fiche principale est affichée).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$tech = $data['tech'];
$isNew = $tech === null;
$techId = $isNew ? 0 : (int) $tech['id'];
$skillIds = $data['tech_skill_ids'];
$zoneIds = $data['tech_zone_ids'];
$weekdays = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'Nouveau technicien' : $e(trim($tech['first_name'] . ' ' . $tech['last_name'])) ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/techniciens">← Techniciens</a></p>

        <?php if (!empty($data['error'])): ?><p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p><?php endif; ?>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <h1><?= $isNew ? 'Nouveau technicien' : $e(trim($tech['first_name'] . ' ' . $tech['last_name'])) ?></h1>

        <!-- Fiche principale -->
        <form method="post" action="<?= $isNew ? '/admin/techniciens' : '/admin/techniciens/' . $techId ?>" class="kn-card">
            <?= $data['csrf'] ?>
            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="first_name">Prénom</label><input type="text" id="first_name" name="first_name" value="<?= $e($tech['first_name'] ?? '') ?>" required></div>
                <div class="kn-field"><label for="last_name">Nom</label><input type="text" id="last_name" name="last_name" value="<?= $e($tech['last_name'] ?? '') ?>" required></div>
            </div>
            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="phone">Téléphone</label><input type="text" id="phone" name="phone" value="<?= $e($tech['phone'] ?? '') ?>" inputmode="tel"></div>
                <div class="kn-field"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= $e($tech['email'] ?? '') ?>"></div>
            </div>

            <div class="kn-field">
                <label for="user_id">Compte de connexion (app terrain)</label>
                <select id="user_id" name="user_id">
                    <option value="0">— Aucun —</option>
                    <?php foreach ($data['linkable_users'] as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($tech['user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= $e(trim($u['first_name'] . ' ' . $u['last_name'])) ?> — <?= $e($u['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="kn-muted" style="margin-top:6px;">Seuls les comptes de rôle « technicien » apparaissent. Créez-en un dans <a href="/admin/utilisateurs">Utilisateurs</a>.</p>
            </div>

            <h3>Point de départ (calcul de trajet)</h3>
            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="home_postal">Code postal</label><input type="text" id="home_postal" name="home_postal" value="<?= $e($tech['home_postal'] ?? '') ?>" inputmode="numeric"></div>
                <div class="kn-field"><label for="max_jobs_per_day">Jobs max / jour</label><input type="number" id="max_jobs_per_day" name="max_jobs_per_day" min="1" value="<?= (int) ($tech['max_jobs_per_day'] ?? 6) ?>"></div>
            </div>
            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="home_lat">Latitude (optionnel)</label><input type="text" id="home_lat" name="home_lat" value="<?= $e($tech['home_lat'] ?? '') ?>" inputmode="decimal"></div>
                <div class="kn-field"><label for="home_lng">Longitude (optionnel)</label><input type="text" id="home_lng" name="home_lng" value="<?= $e($tech['home_lng'] ?? '') ?>" inputmode="decimal"></div>
            </div>

            <label class="kn-check">
                <input type="checkbox" name="is_active" value="1" <?= ($isNew || (int) ($tech['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                Technicien actif (proposé au planning)
            </label>

            <button type="submit" class="kn-btn kn-btn-primary"><?= $isNew ? 'Créer le technicien' : 'Enregistrer la fiche' ?></button>
        </form>

        <?php if (!$isNew): ?>
            <form method="post" action="/admin/techniciens/<?= $techId ?>/supprimer" style="margin-top:12px;"
                  onsubmit="return confirm('Supprimer ce technicien ? S\'il a déjà des rendez-vous, il sera désactivé plutôt que supprimé.');">
                <?= $data['csrf'] ?>
                <button type="submit" class="kn-btn kn-btn-danger">Supprimer le technicien</button>
            </form>
        <?php endif; ?>

        <?php if (!$isNew): ?>
            <!-- Compétences -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Compétences</h2>
                <?php if ($data['skills'] === []): ?>
                    <p class="kn-muted">Aucune compétence au catalogue. <a href="/admin/competences">En créer</a>.</p>
                <?php else: ?>
                    <form method="post" action="/admin/techniciens/<?= $techId ?>/competences">
                        <?= $data['csrf'] ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px 20px;margin-bottom:16px;">
                            <?php foreach ($data['skills'] as $s): ?>
                                <label class="kn-check" style="margin:0;">
                                    <input type="checkbox" name="skills[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $skillIds, true) ? 'checked' : '' ?>>
                                    <?= $e($s['label']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Enregistrer les compétences</button>
                    </form>
                <?php endif; ?>
            </section>

            <!-- Zones de service couvertes -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Zones couvertes</h2>
                <p class="kn-muted">Détermine dans quelles zones de chalandise ce technicien peut être proposé. Gérable aussi depuis chaque <a href="/admin/zones">fiche zone</a>.</p>
                <?php if ($data['zones'] === []): ?>
                    <p class="kn-muted">Aucune zone active. <a href="/admin/zones/nouvelle">En créer</a>.</p>
                <?php else: ?>
                    <form method="post" action="/admin/techniciens/<?= $techId ?>/zones">
                        <?= $data['csrf'] ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px 20px;margin-bottom:16px;">
                            <?php foreach ($data['zones'] as $z): ?>
                                <label class="kn-check" style="margin:0;">
                                    <input type="checkbox" name="zones[]" value="<?= (int) $z['id'] ?>" <?= in_array((int) $z['id'], $zoneIds, true) ? 'checked' : '' ?>>
                                    <?= $e($z['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Enregistrer les zones</button>
                    </form>
                <?php endif; ?>
            </section>

            <!-- Disponibilités récurrentes -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Disponibilités récurrentes</h2>
                <p class="kn-muted">Plages hebdomadaires en heure belge. Rattachez à un atelier pour distinguer disponibilité terrain / atelier.</p>
                <div class="kn-table-wrap">
                    <table class="kn-table">
                        <thead><tr><th>Jour</th><th>Début</th><th>Fin</th><th>Atelier</th><th>Validité</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($data['availability'] as $a): ?>
                                <tr>
                                    <td><?= $e($weekdays[(int) $a['weekday']] ?? '?') ?></td>
                                    <td><?= $e(substr((string) $a['start_time'], 0, 5)) ?></td>
                                    <td><?= $e(substr((string) $a['end_time'], 0, 5)) ?></td>
                                    <td class="kn-muted"><?= $e($a['location_name'] ?? 'Terrain') ?></td>
                                    <td class="kn-muted"><?= $e(($a['valid_from'] ?? '') !== '' ? $a['valid_from'] : '—') ?><?= ($a['valid_until'] ?? '') !== '' ? ' → ' . $e($a['valid_until']) : '' ?></td>
                                    <td>
                                        <form method="post" action="/admin/techniciens/<?= $techId ?>/disponibilite/<?= (int) $a['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cette plage ?');">
                                            <?= $data['csrf'] ?>
                                            <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Suppr.</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($data['availability'] === []): ?><tr><td colspan="6" class="kn-muted">Aucune plage.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <details style="margin-top:12px;">
                    <summary>Ajouter une plage</summary>
                    <form method="post" action="/admin/techniciens/<?= $techId ?>/disponibilite" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;"><label>Jour</label><select name="weekday">
                            <?php foreach ($weekdays as $num => $label): ?><option value="<?= $num ?>"><?= $e($label) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="kn-field" style="margin:0;"><label>Début</label><input type="time" name="start_time" required style="max-width:120px;"></div>
                        <div class="kn-field" style="margin:0;"><label>Fin</label><input type="time" name="end_time" required style="max-width:120px;"></div>
                        <div class="kn-field" style="margin:0;"><label>Atelier</label><select name="location_id">
                            <option value="0">Terrain (aucun)</option>
                            <?php foreach ($data['locations'] as $loc): ?><option value="<?= (int) $loc['id'] ?>"><?= $e($loc['name']) ?></option><?php endforeach; ?>
                        </select></div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                    </form>
                </details>
            </section>

            <!-- Absences -->
            <section class="kn-card" style="margin-top:24px;">
                <h2>Absences / congés</h2>
                <p class="kn-muted">Saisies en heure belge, bloquent la génération de créneaux sur la période.</p>
                <div class="kn-table-wrap">
                    <table class="kn-table">
                        <thead><tr><th>Du</th><th>Au</th><th>Motif</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($data['time_off'] as $o): ?>
                                <tr>
                                    <td><?= $e(\Keepnew\Support\Clock::format(new \DateTimeImmutable($o['starts_at'] . ' UTC'))) ?></td>
                                    <td><?= $e(\Keepnew\Support\Clock::format(new \DateTimeImmutable($o['ends_at'] . ' UTC'))) ?></td>
                                    <td class="kn-muted"><?= $e($o['reason'] ?? '—') ?></td>
                                    <td>
                                        <form method="post" action="/admin/techniciens/<?= $techId ?>/absence/<?= (int) $o['id'] ?>/supprimer" onsubmit="return confirm('Supprimer cette absence ?');">
                                            <?= $data['csrf'] ?>
                                            <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Suppr.</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($data['time_off'] === []): ?><tr><td colspan="4" class="kn-muted">Aucune absence.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <details style="margin-top:12px;">
                    <summary>Ajouter une absence</summary>
                    <form method="post" action="/admin/techniciens/<?= $techId ?>/absence" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px;">
                        <?= $data['csrf'] ?>
                        <div class="kn-field" style="margin:0;"><label>Début</label><input type="datetime-local" name="starts_at" required></div>
                        <div class="kn-field" style="margin:0;"><label>Fin</label><input type="datetime-local" name="ends_at" required></div>
                        <div class="kn-field" style="margin:0;flex:1;"><label>Motif</label><input type="text" name="reason" placeholder="Congé, formation…"></div>
                        <button type="submit" class="kn-btn kn-btn-ghost">Ajouter</button>
                    </form>
                </details>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
