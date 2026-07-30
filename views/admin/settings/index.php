<?php
/**
 * Vue : réglages généraux (contact, CGV, délai libre-service client).
 * @var array<string,mixed> $data
 * @var callable $e
 */
$v = $data['values'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Réglages — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/../_nav.php'; ?>

    <main class="kn-wrap kn-wrap-narrow">
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>
        <h1>Réglages</h1>

        <form method="post" action="/admin/reglages" class="kn-card">
            <?= $data['csrf'] ?>

            <h2>Contact</h2>
            <div class="kn-field">
                <label for="company_phone">Téléphone</label>
                <input type="text" id="company_phone" name="company_phone" value="<?= $e($v['company.phone'] ?? '') ?>">
            </div>
            <div class="kn-field">
                <label for="company_email">E-mail</label>
                <input type="email" id="company_email" name="company_email" value="<?= $e($v['company.email'] ?? '') ?>">
            </div>

            <h2>Conditions générales</h2>
            <div class="kn-field">
                <label for="company_terms_url">Lien vers les CGV</label>
                <input type="url" id="company_terms_url" name="company_terms_url" value="<?= $e($v['company.terms_url'] ?? '') ?>" placeholder="https://…">
            </div>

            <h2>Avis clients</h2>
            <div class="kn-field">
                <label for="company_review_url">Lien où déposer un avis (Google, Trustpilot…)</label>
                <input type="url" id="company_review_url" name="company_review_url" value="<?= $e($v['company.review_url'] ?? '') ?>" placeholder="https://g.page/r/…/review">
                <p class="kn-muted" style="margin-top:6px;">
                    Utilisé par la variable <code>{{review.url}}</code> du modèle « Demande d'avis », envoyé
                    depuis la fiche d'un rendez-vous terminé. Sans lien, le message part sans URL cliquable.
                </p>
            </div>

            <h2>Signature email</h2>
            <div class="kn-field">
                <label for="company_email_signature_html">Signature HTML (ajoutée automatiquement en fin de chaque email envoyé)</label>
                <textarea id="company_email_signature_html" name="company_email_signature_html" rows="6" style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:4px;font:inherit;font-family:monospace;font-size:.85rem;"><?= $e($v['company.email_signature_html'] ?? '') ?></textarea>
                <p class="kn-muted" style="margin-top:6px;">
                    Code HTML (liens, boutons, logo…) — mêmes variables que les modèles de notification, ex. <code>{{booking.manage_url}}</code>.
                    Laisser vide pour ne rien ajouter.
                </p>
            </div>

            <h2>Espace client (mes-rdv)</h2>
            <div class="kn-field" style="max-width:260px;">
                <label for="booking_self_service_deadline_hours">Délai de modification/annulation (heures avant le RDV)</label>
                <input type="number" id="booking_self_service_deadline_hours" name="booking_self_service_deadline_hours" min="1" value="<?= $e($v['booking.self_service_deadline_hours'] ?? '48') ?>">
                <p class="kn-muted" style="margin-top:6px;">Passé ce délai, le client ne peut plus modifier ni annuler lui-même sa réservation ; un message avec vos coordonnées et le lien CGV s'affiche à la place.</p>
            </div>

            <button type="submit" class="kn-btn kn-btn-primary">Enregistrer</button>
        </form>
    </main>
</body>
</html>
