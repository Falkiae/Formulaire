<?php
/**
 * Contenu de la fiche job (partagé) : rendu en page complète par
 * views/admin/job.php, ou en fragment (fetch) par le panneau coulissant du
 * calendrier/dispatch (public/assets/admin-job-panel.js). Inclus via
 * View::render()/capture() — reçoit $data/$e comme toute vue.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$j = $data['job'];
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');

$statusFlow = [
    'scheduled' => 'Programmé',
    'en_route' => 'En route',
    'in_progress' => 'Démarré',
    'completed' => 'Terminé',
];
$flowKeys = array_keys($statusFlow);
$currentIdx = array_search($j['status'], $flowKeys, true);
$isException = in_array($j['status'], ['cancelled', 'no_show'], true);

$title = $data['items'] !== []
    ? implode(' + ', array_map(static fn (array $it): string => $it['label_snapshot'], array_slice($data['items'], 0, 2)))
    : 'Rendez-vous ' . $j['reference'];
?>
<div class="kn-job-panel" data-job-id="<?= (int) $j['id'] ?>">
    <div class="kn-job-panel-head">
        <div>
            <h2 class="kn-job-panel-title"><?= $e($title) ?> <span class="kn-muted">pour <?= $e($j['first_name'] . ' ' . $j['last_name']) ?></span></h2>
            <p class="kn-muted" style="margin:2px 0 0;">Job #<?= $e($j['reference']) ?></p>
        </div>
        <button type="button" class="kn-job-panel-close" aria-label="Fermer">✕</button>
    </div>

    <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

    <?php if ($isException): ?>
        <p class="kn-alert"><?= $j['status'] === 'cancelled' ? 'Rendez-vous annulé.' : 'Client absent.' ?></p>
    <?php endif; ?>

    <div class="kn-status-stepper" role="group" aria-label="Statut">
        <?php foreach ($statusFlow as $key => $label): ?>
            <?php
            $idx = array_search($key, $flowKeys, true);
            $state = $isException ? '' : ($idx < $currentIdx ? 'is-done' : ($idx === $currentIdx ? 'is-active' : ''));
            ?>
            <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/statut" class="kn-status-step-form">
                <?= $data['csrf'] ?>
                <input type="hidden" name="status" value="<?= $e($key) ?>">
                <button type="submit" class="kn-status-step <?= $state ?>"><?= $e($label) ?></button>
            </form>
        <?php endforeach; ?>
    </div>

    <?php if (!$isException && $j['status'] !== 'completed'): ?>
        <?php
        // Une commande = un mode = un rendez-vous. Le bouton par rendez-vous
        // n'a de sens que sur les commandes antérieures à cette règle, qui en
        // comptent deux (domicile + atelier) ; ailleurs il ferait doublon avec
        // l'annulation de commande tout en laissant la commande ouverte sans
        // aucune intervention.
        $multiJob = (int) ($data['booking_job_count'] ?? 1) > 1;
        $modeLabel = $j['mode'] === 'onsite' ? 'à domicile' : 'en atelier';
        ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;">
            <?php if ($multiJob): ?>
                <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/statut"
                      onsubmit="return confirm('Annuler le rendez-vous <?= $e($modeLabel) ?> ? L\'autre rendez-vous de cette commande n\'est pas affecté, et la commande reste ouverte.');">
                    <?= $data['csrf'] ?>
                    <input type="hidden" name="status" value="cancelled">
                    <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Annuler ce rendez-vous (<?= $e($modeLabel) ?>)</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/annuler-commande"
                  onsubmit="return confirm('Annuler la commande ? <?= $multiJob ? 'Tous ses rendez-vous non terminés seront annulés.' : 'Le rendez-vous et la commande seront annulés.' ?>');">
                <?= $data['csrf'] ?>
                <button type="submit" class="kn-btn kn-btn-danger kn-btn-sm">Annuler la commande</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($j['status'] === 'completed'): ?>
        <?php $reviewSentAt = $data['review_sent_at'] ?? null; ?>
        <div style="margin:8px 0;">
            <?php if ($reviewSentAt !== null): ?>
                <p class="kn-muted" style="margin:0;">
                    Demande d'avis envoyée le <?= $e(substr($reviewSentAt, 0, 16)) ?>.
                </p>
            <?php else: ?>
                <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/demande-avis"
                      onsubmit="return confirm('Envoyer la demande d\'avis à <?= $e(trim($j['first_name'] . ' ' . $j['last_name'])) ?> ?');">
                    <?= $data['csrf'] ?>
                    <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Envoyer une demande d'avis</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <span class="kn-badge kn-badge-<?= $e($j['mode']) ?>"><?= $j['mode'] === 'onsite' ? 'À domicile' : 'Atelier' ?></span>
    <?php if ($j['tech_first']): ?><span class="kn-muted">· Technicien : <?= $e($j['tech_first'] . ' ' . $j['tech_last']) ?></span><?php endif; ?>

    <?php if ($j['mode'] === 'onsite' && $j['lat'] !== null && $j['lng'] !== null): ?>
        <div class="kn-job-map" id="kn-job-map" data-lat="<?= $e((string) $j['lat']) ?>" data-lng="<?= $e((string) $j['lng']) ?>"></div>
    <?php endif; ?>

    <section class="kn-card" style="margin-top:12px;">
        <h3 style="margin-top:0;">Adresse d'intervention</h3>
        <?php if ($j['mode'] === 'onsite'): ?>
            <p><?= $e(trim(($j['street'] ?? '') . ' ' . ($j['number'] ?? '') . ', ' . ($j['postal_code'] ?? '') . ' ' . ($j['city'] ?? ''))) ?></p>
            <?php if (!empty($j['access_notes'])): ?><p class="kn-muted">Accès : <?= $e($j['access_notes']) ?></p><?php endif; ?>
        <?php else: ?>
            <p class="kn-muted">Intervention en atelier.</p>
        <?php endif; ?>
        <p><a href="/admin/client/<?= (int) $j['customer_id'] ?>"><?= $e($j['first_name'] . ' ' . $j['last_name']) ?></a> · <?= $e($j['phone'] ?? '') ?> · <?= $e($j['email'] ?? '') ?></p>
    </section>

    <section class="kn-card" style="margin-top:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 style="margin:0;">Date et heure</h3>
                <p style="margin:4px 0 0;font-size:1.1rem;font-weight:600;">
                    <?= $j['scheduled_start'] !== null ? $e(\Keepnew\Support\Clock::format(new \DateTimeImmutable((string) $j['scheduled_start'] . ' UTC'), 'l d/m/Y à H:i')) : 'Non planifié' ?>
                </p>
            </div>
            <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm" id="kn-open-resched">Replanifier</button>
        </div>

        <div id="kn-resched-panel" hidden>
            <p class="kn-muted">Seuls les créneaux réellement libres (horaires, congés et rendez-vous déjà posés pris en compte) sont proposés.</p>

            <div class="kn-mode-toggle" role="radiogroup" aria-label="Mode recherché">
                <label class="kn-check">
                    <input type="radio" name="kn-resched-target-mode" value="onsite" <?= $j['mode'] === 'onsite' ? 'checked' : '' ?>>
                    Domicile
                </label>
                <label class="kn-check">
                    <input type="radio" name="kn-resched-target-mode" value="workshop" <?= $j['mode'] === 'workshop' ? 'checked' : '' ?>>
                    Atelier
                </label>
                <?php if (count($data['customer_addresses']) > 1): ?>
                    <select id="kn-resched-address-select" class="kn-field" style="display:<?= $j['mode'] === 'onsite' ? '' : 'none' ?>;">
                        <?php foreach ($data['customer_addresses'] as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= (int) ($j['address_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                <?= $e(trim((($a['label'] ?? '') !== '' ? $a['label'] . ' · ' : '') . ($a['street'] ?? '') . ' ' . ($a['number'] ?? '') . ', ' . ($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="kn-resched-picker" id="kn-resched-picker" data-job-id="<?= (int) $j['id'] ?>" data-current-tech="<?= (int) ($j['technician_id'] ?? 0) ?>" data-current-mode="<?= $e($j['mode']) ?>" data-default-address-id="<?= (int) ($data['customer_addresses'][0]['id'] ?? 0) ?>">
                <div class="kn-resched-cal">
                    <div class="kn-resched-cal-head">
                        <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm" id="kn-resched-prev">←</button>
                        <span id="kn-resched-month"></span>
                        <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm" id="kn-resched-next">→</button>
                    </div>
                    <div class="kn-resched-cal-grid" id="kn-resched-cal-grid"></div>
                </div>
                <div class="kn-resched-slots">
                    <label class="kn-check">
                        <input type="checkbox" id="kn-resched-only-current">
                        Ne montrer que les disponibilités de <?= $j['tech_first'] ? $e($j['tech_first']) : 'ce technicien' ?>
                    </label>
                    <div id="kn-resched-slot-list"></div>
                    <button type="button" class="kn-btn kn-btn-primary kn-btn-full" id="kn-resched-confirm" style="margin-top:10px;" disabled>Confirmer la replanification</button>
                </div>
            </div>

            <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/planifier" id="kn-resched-form">
                <?= $data['csrf'] ?>
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="scheduled_start" id="kn-resched-start">
                <input type="hidden" name="technician_id" id="kn-resched-tech">
                <input type="hidden" name="mode" id="kn-resched-mode-input" value="<?= $e($j['mode']) ?>">
                <input type="hidden" name="bay_id" id="kn-resched-bay-input" value="<?= (int) ($j['bay_id'] ?? 0) ?>">
                <input type="hidden" name="address_id" id="kn-resched-address-input" value="<?= (int) ($j['address_id'] ?? 0) ?>">
            </form>
        </div>
    </section>

    <?php
    $bk = $data['booking'] ?? null;
    $block = $data['edit_block'] ?? null;   // null = commande retouchable
    $jobId = (int) $j['id'];
    // Saisie en euros : les montants restent en centimes en base, la conversion
    // se fait côté serveur (JobController::cents).
    $euroInput = static fn (int $cents): string => number_format($cents / 100, 2, '.', '');
    ?>
    <section class="kn-card" style="margin-top:12px;">
        <h3 style="margin-top:0;">Prestations</h3>

        <?php if ($block !== null): ?>
            <p class="kn-alert"><?= $e($block) ?></p>
        <?php endif; ?>

        <div class="kn-table-wrap">
            <table class="kn-table">
                <thead>
                    <tr><th>Prestation</th><th style="width:78px;">Qté</th><th style="width:110px;">P.U. (€)</th><th class="kn-num">Total</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($data['items'] as $it): ?>
                    <tr>
                        <?php if ($block !== null): ?>
                            <td><?= $e($it['label_snapshot']) ?></td>
                            <td><?= (int) $it['quantity'] ?></td>
                            <td><?= $e($eur((int) $it['unit_price_cents'])) ?></td>
                        <?php else: ?>
                            <td>
                                <?= $e($it['label_snapshot']) ?>
                                <?php if ($it['service_id'] === null): ?>
                                    <span class="kn-badge">sur mesure</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input form="kn-line-<?= (int) $it['id'] ?>" type="number" name="quantity" min="1"
                                       value="<?= (int) $it['quantity'] ?>" style="width:64px;">
                            </td>
                            <td>
                                <input form="kn-line-<?= (int) $it['id'] ?>" type="text" name="price" inputmode="decimal"
                                       value="<?= $e($euroInput((int) $it['unit_price_cents'])) ?>" style="width:96px;">
                            </td>
                        <?php endif; ?>
                        <td class="kn-num"><?= $e($eur((int) $it['line_total_cents'])) ?> €</td>
                        <td>
                            <?php if ($block === null): ?>
                                <form method="post" id="kn-line-<?= (int) $it['id'] ?>"
                                      action="/admin/job/<?= $jobId ?>/ligne/<?= (int) $it['id'] ?>" style="display:inline;">
                                    <?= $data['csrf'] ?>
                                    <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Enregistrer</button>
                                </form>
                                <form method="post" action="/admin/job/<?= $jobId ?>/ligne/<?= (int) $it['id'] ?>/supprimer"
                                      style="display:inline;" onsubmit="return confirm('Retirer cette prestation de la commande ?');">
                                    <?= $data['csrf'] ?>
                                    <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Retirer</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($it['extras'] !== [] || ($block === null && $it['attachable_extras'] !== [])): ?>
                        <tr>
                            <td colspan="5" style="padding-top:0;">
                                <?php foreach ($it['extras'] as $ex): ?>
                                    <span class="kn-badge" style="margin-right:6px;">
                                        + <?= $e($ex['label_snapshot']) ?> · <?= $e($eur((int) $ex['unit_price_cents'])) ?> €
                                        <?php if ((int) $ex['unit_duration_min'] > 0): ?>· <?= (int) $ex['unit_duration_min'] ?> min<?php endif; ?>
                                        <?php if ($block === null): ?>
                                            <form method="post" style="display:inline;"
                                                  action="/admin/job/<?= $jobId ?>/ligne/<?= (int) $it['id'] ?>/extra/<?= (int) $ex['id'] ?>/supprimer">
                                                <?= $data['csrf'] ?>
                                                <button type="submit" class="kn-btn-inline-x" aria-label="Retirer l'extra <?= $e($ex['label_snapshot']) ?>">✕</button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($it['extras'] === []): ?><span class="kn-muted" style="font-size:.8rem;">Aucun extra.</span><?php endif; ?>

                                <?php if ($block === null && $it['attachable_extras'] !== []): ?>
                                    <form method="post" action="/admin/job/<?= $jobId ?>/ligne/<?= (int) $it['id'] ?>/extra"
                                          style="display:inline-flex;gap:6px;align-items:center;margin-top:6px;">
                                        <?= $data['csrf'] ?>
                                        <select name="extra_id" aria-label="Extra à ajouter">
                                            <?php foreach ($it['attachable_extras'] as $ax): ?>
                                                <option value="<?= (int) $ax['extra_id'] ?>">
                                                    <?= $e($ax['label']) ?> — <?= $e($eur((int) $ax['eff_price_cents'])) ?> €
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">+ Extra</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($data['items'] === []): ?>
                    <tr><td colspan="5" class="kn-muted">Aucune prestation.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($bk !== null): ?>
                    <tfoot>
                        <tr><td colspan="3">Sous-total</td><td class="kn-num"><?= $e($eur((int) $bk['subtotal_cents'])) ?> €</td><td></td></tr>
                        <?php if ((int) $bk['discount_cents'] > 0): ?>
                            <tr><td colspan="3">Remise</td><td class="kn-num">− <?= $e($eur((int) $bk['discount_cents'])) ?> €</td><td></td></tr>
                        <?php endif; ?>
                        <?php if ((int) $bk['travel_surcharge_cents'] > 0): ?>
                            <tr><td colspan="3">Déplacement</td><td class="kn-num"><?= $e($eur((int) $bk['travel_surcharge_cents'])) ?> €</td><td></td></tr>
                        <?php endif; ?>
                        <tr><td colspan="3">TVA <?= $e(number_format(((int) $bk['vat_rate_bp']) / 100, 0)) ?> %</td><td class="kn-num"><?= $e($eur((int) $bk['vat_cents'])) ?> €</td><td></td></tr>
                        <tr><td colspan="3"><strong>Total TVAC</strong></td><td class="kn-num"><strong><?= $e($eur((int) $bk['total_cents'])) ?> €</strong></td><td></td></tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($block === null): ?>
            <details style="margin-top:12px;">
                <summary style="cursor:pointer;font-weight:600;">Ajouter une prestation</summary>

                <form method="post" action="/admin/job/<?= $jobId ?>/ligne" class="kn-row" style="margin-top:8px;">
                    <?= $data['csrf'] ?>
                    <input type="hidden" name="kind" value="catalog">
                    <div class="kn-field" style="flex:1 1 220px;">
                        <label for="kn-add-service">Depuis le catalogue</label>
                        <select id="kn-add-service" name="service_id" required>
                            <?php foreach ($data['catalog_services'] as $s): ?>
                                <option value="<?= (int) $s['id'] ?>"><?= $e($s['name']) ?> — <?= $e($eur((int) ($s['price_cents'] ?? 0))) ?> €</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="kn-field" style="flex:0 0 80px;">
                        <label for="kn-add-qty">Qté</label>
                        <input id="kn-add-qty" type="number" name="quantity" min="1" value="1">
                    </div>
                    <button type="submit" class="kn-btn kn-btn-primary kn-btn-sm">Ajouter</button>
                </form>

                <form method="post" action="/admin/job/<?= $jobId ?>/ligne" class="kn-row" style="margin-top:8px;">
                    <?= $data['csrf'] ?>
                    <input type="hidden" name="kind" value="custom">
                    <div class="kn-field" style="flex:1 1 200px;">
                        <label for="kn-custom-label">Sur mesure — libellé</label>
                        <input id="kn-custom-label" type="text" name="label" placeholder="Ex. Traitement anti-odeur" required>
                    </div>
                    <div class="kn-field" style="flex:0 0 110px;">
                        <label for="kn-custom-price">Prix (€)</label>
                        <input id="kn-custom-price" type="text" name="price" inputmode="decimal" value="0.00">
                    </div>
                    <div class="kn-field" style="flex:0 0 80px;">
                        <label for="kn-custom-qty">Qté</label>
                        <input id="kn-custom-qty" type="number" name="quantity" min="1" value="1">
                    </div>
                    <div class="kn-field" style="flex:0 0 110px;">
                        <label for="kn-custom-duration">Durée (min)</label>
                        <input id="kn-custom-duration" type="number" name="duration_min" min="0" value="0">
                    </div>
                    <button type="submit" class="kn-btn kn-btn-primary kn-btn-sm">Ajouter</button>
                </form>
            </details>

            <form method="post" action="/admin/job/<?= $jobId ?>/remise" class="kn-row" style="margin-top:12px;">
                <?= $data['csrf'] ?>
                <div class="kn-field" style="flex:0 0 120px;">
                    <label for="kn-discount">Remise</label>
                    <input id="kn-discount" type="text" name="value" inputmode="decimal"
                           value="<?= $e($euroInput((int) ($bk['discount_cents'] ?? 0))) ?>">
                </div>
                <div class="kn-field" style="flex:0 0 90px;">
                    <label for="kn-discount-unit">Unité</label>
                    <select id="kn-discount-unit" name="unit">
                        <option value="amount">€</option>
                        <option value="percent">%</option>
                    </select>
                </div>
                <button type="submit" class="kn-btn kn-btn-ghost kn-btn-sm">Appliquer la remise</button>
            </form>
            <p class="kn-muted" style="font-size:.8rem;margin-top:8px;">
                Montants hors TVA ; la TVA et le total sont recalculés à chaque modification.
                La remise saisie remplace la précédente (elle ne s'y ajoute pas).
            </p>
        <?php endif; ?>
    </section>

    <section class="kn-card" style="margin-top:12px;">
        <h3 style="margin-top:0;">Réponses au formulaire</h3>
        <?php if ($data['answers'] === []): ?><p class="kn-muted">Aucune.</p><?php endif; ?>
        <?php foreach ($data['answers'] as $a): ?>
            <p><strong><?= $e($a['field_key']) ?></strong> : <?= $e($a['value_text']) ?></p>
        <?php endforeach; ?>
    </section>

    <section class="kn-card" style="margin-top:12px;">
        <h3 style="margin-top:0;">Photos avant / après</h3>
        <?php if ($data['photos'] === []): ?><p class="kn-muted">Aucune photo pour le moment.</p><?php endif; ?>
        <?php foreach ($data['photos'] as $p): ?><p><?= $e($p['kind']) ?> : <?= $e($p['file_path']) ?></p><?php endforeach; ?>
    </section>

    <section class="kn-card" style="margin-top:12px;">
        <h3 style="margin-top:0;">Note interne</h3>
        <form method="post" action="/admin/job/<?= (int) $j['id'] ?>/note">
            <?= $data['csrf'] ?>
            <input type="hidden" name="customer_id" value="<?= (int) $j['customer_id'] ?>">
            <div class="kn-field">
                <textarea name="body" rows="3" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:4px;resize:vertical;"></textarea>
            </div>
            <button type="submit" class="kn-btn kn-btn-ghost">Ajouter la note</button>
        </form>
        <?php foreach ($data['notes'] as $n): ?>
            <p class="kn-muted"><?= $e($n['created_at']) ?> — <?= $e($n['first_name'] ?? '?') ?> : <?= $e($n['body']) ?></p>
        <?php endforeach; ?>
    </section>

    <section class="kn-card" style="margin-top:12px;margin-bottom:12px;">
        <h3 style="margin-top:0;">Historique</h3>
        <?php foreach ($data['history'] as $h): ?>
            <p class="kn-muted"><?= $e($h['created_at']) ?> — <?= $e($h['old_status'] ?? '∅') ?> → <strong><?= $e($h['new_status']) ?></strong> <?= $e($h['note'] ?? '') ?></p>
        <?php endforeach; ?>
    </section>
</div>
