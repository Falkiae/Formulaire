<?php
/**
 * Vue publique : gestion d'une réservation (consultation, replanification,
 * annulation) via manage_token, sans compte. Accessible jusqu'au délai
 * configuré avant le rendez-vous ; passé ce délai, affiche un message de
 * contact à la place des actions.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$b = $data['booking'];
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ');
$statusLabels = [
    'draft' => 'Brouillon', 'pending' => 'En attente', 'confirmed' => 'Confirmée',
    'in_progress' => 'En cours', 'completed' => 'Terminée', 'cancelled' => 'Annulée', 'no_show' => 'Client absent',
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ma réservation — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        body { background: var(--kn-paper); }
        .kn-manage-wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 60px; }
        .kn-manage-logo { font-family: var(--kn-font-display); font-weight: 600; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.22em; margin-bottom: 20px; display: block; }
    </style>
</head>
<body>
    <div class="kn-manage-wrap">
        <span class="kn-manage-logo">Keepnew</span>

        <?php if ($b === null): ?>
            <section class="kn-card">
                <p class="kn-alert kn-alert-error">Réservation introuvable. Vérifiez le lien reçu par e-mail, ou contactez-nous.</p>
                <?php if (!empty($data['contact_phone']) || !empty($data['contact_email'])): ?>
                    <p class="kn-muted">
                        <?php if (!empty($data['contact_phone'])): ?><a href="tel:<?= $e($data['contact_phone']) ?>"><?= $e($data['contact_phone']) ?></a><?php endif; ?>
                        <?php if (!empty($data['contact_email'])): ?> · <a href="mailto:<?= $e($data['contact_email']) ?>"><?= $e($data['contact_email']) ?></a><?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <?php if (!empty($data['msg'])): ?>
                <p class="kn-alert <?= $data['msg_type'] === 'ok' ? 'kn-alert-ok' : 'kn-alert-error' ?>"><?= $e($data['msg']) ?></p>
            <?php endif; ?>

            <section class="kn-card">
                <h1 style="margin-top:0;">Réservation <?= $e($b['reference']) ?></h1>
                <span class="kn-badge"><?= $e($statusLabels[$b['status']] ?? $b['status']) ?></span>
                <p class="kn-num" style="font-size:1.1rem;font-weight:600;margin-top:8px;"><?= $e($eur((int) $b['total_cents'])) ?> €</p>

                <h3>Prestations</h3>
                <?php foreach ($b['items'] as $it): ?>
                    <p><?= $e($it['label_snapshot']) ?> ×<?= (int) $it['quantity'] ?> — <?= $e($eur((int) $it['line_total_cents'])) ?> €</p>
                <?php endforeach; ?>
            </section>

            <?php foreach ($b['jobs'] as $job): ?>
                <?php $jobDone = in_array($job['status'], ['cancelled', 'completed'], true); ?>
                <section class="kn-card" style="margin-top:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span class="kn-badge kn-badge-<?= $e($job['mode']) ?>"><?= $job['mode'] === 'onsite' ? 'À domicile' : 'Atelier' ?></span>
                            <p style="margin:6px 0 0;font-weight:600;"><?= $job['scheduled_local'] !== null ? $e($job['scheduled_local']) : 'Pas encore planifié' ?></p>
                        </div>
                        <?php if (!$jobDone && !empty($data['within_window'])): ?>
                            <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm kn-manage-open-resched" data-job-id="<?= (int) $job['id'] ?>">Modifier la date/heure</button>
                        <?php endif; ?>
                    </div>

                    <?php if (!$jobDone && !empty($data['within_window'])): ?>
                        <div class="kn-resched-panel" data-job-id="<?= (int) $job['id'] ?>" data-token="<?= $e($data['token']) ?>" hidden style="margin-top:12px;">
                            <div class="kn-resched-picker">
                                <div class="kn-resched-cal">
                                    <div class="kn-resched-cal-head">
                                        <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm kn-manage-prev">←</button>
                                        <span class="kn-manage-month"></span>
                                        <button type="button" class="kn-btn kn-btn-ghost kn-btn-sm kn-manage-next">→</button>
                                    </div>
                                    <div class="kn-resched-cal-grid kn-manage-cal-grid"></div>
                                </div>
                                <div class="kn-resched-slots">
                                    <div class="kn-manage-slot-list"></div>
                                </div>
                            </div>
                            <form method="post" action="/rdv/<?= $e($data['token']) ?>/replanifier" class="kn-manage-resched-form">
                                <?= $data['csrf'] ?>
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="date" class="kn-manage-date-input">
                                <input type="hidden" name="time" class="kn-manage-time-input">
                            </form>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if (!empty($data['within_window'])): ?>
                <?php if (!in_array($b['status'], ['cancelled', 'completed'], true)): ?>
                    <section class="kn-card" style="margin-top:16px;">
                        <form method="post" action="/rdv/<?= $e($data['token']) ?>/annuler"
                              onsubmit="return confirm('Annuler cette réservation ?');">
                            <?= $data['csrf'] ?>
                            <button type="submit" class="kn-btn kn-btn-danger">Annuler ma réservation</button>
                        </form>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <section class="kn-card" style="margin-top:16px;">
                    <h3 style="margin-top:0;">Délai de modification dépassé</h3>
                    <p>Il n'est plus possible de modifier ou d'annuler cette réservation en ligne. En cas d'annulation tardive, des frais peuvent être facturés conformément à nos conditions générales de vente<?= !empty($data['terms_url']) ? ' (<a href="' . $e($data['terms_url']) . '" target="_blank" rel="noopener">consulter les CGV</a>)' : '' ?>.</p>
                    <p>Pour toute demande, contactez-nous directement :</p>
                    <p>
                        <?php if (!empty($data['contact_phone'])): ?><a href="tel:<?= $e($data['contact_phone']) ?>"><?= $e($data['contact_phone']) ?></a><?php endif; ?>
                        <?php if (!empty($data['contact_email'])): ?> · <a href="mailto:<?= $e($data['contact_email']) ?>"><?= $e($data['contact_email']) ?></a><?php endif; ?>
                    </p>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="/assets/manage-booking.js"></script>
</body>
</html>
