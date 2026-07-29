<?php
/**
 * Vue : textes fixes des 9 étapes du tunnel public (titres, sous-titres,
 * boutons, aide, erreurs). Édition libre, effet immédiat côté client dès
 * l'enregistrement — pas de cycle brouillon/publication.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
$texts = $data['texts'];

// Libellé humain + type de champ (text|textarea) pour chaque clé — texte
// long (aide/réassurance/messages) en textarea, le reste en input court.
$stepLabels = [
    'where' => '1. Où',
    'what' => '2. Quoi',
    'details' => '3. Détails',
    'cart' => '4. Panier',
    'intake' => '5. Questions',
    'contact' => '6. Coordonnées',
    'slot' => '7. Rendez-vous',
    'recap' => '8. Récapitulatif',
    'done' => '9. Confirmation',
    'shared' => 'Textes partagés',
];

$fieldMeta = [
    'where' => [
        'title' => ['Titre principal', 'text'],
        'onsite_title' => ['Titre du choix « domicile »', 'text'],
        'onsite_subtitle' => ['Sous-titre du choix « domicile »', 'text'],
        'workshop_title' => ['Titre du choix « atelier »', 'text'],
        'workshop_subtitle' => ['Sous-titre du choix « atelier »', 'text'],
        'postal_label' => ['Libellé du champ code postal', 'text'],
        'postal_placeholder' => ['Exemple affiché dans le champ code postal', 'text'],
        'continue_button' => ['Texte du bouton Continuer', 'text'],
        'zone_reassurance' => ['Texte de réassurance sous le formulaire', 'textarea'],
        'postal_required_error' => ['Message si le code postal est vide', 'textarea'],
        'zone_check_error' => ['Message si la vérification de zone échoue', 'textarea'],
    ],
    'what' => [
        'title' => ['Titre principal', 'text'],
        'back_link' => ['Lien de retour vers les catégories', 'text'],
    ],
    'details' => [
        'title' => ['Titre principal', 'text'],
        'variants_heading' => ['Titre de la section modèles/variantes', 'text'],
        'extras_heading' => ['Titre de la section options', 'text'],
        'price_reassurance' => ['Texte de réassurance sur le prix', 'textarea'],
        'add_to_cart_button' => ['Texte du bouton Ajouter au panier', 'text'],
        'price_duration_label' => ['Texte avant la durée estimée (ex. « TVAC · durée estimée »)', 'text'],
        'unavailable_mode_error' => ['Message si la prestation est indisponible dans ce mode', 'textarea'],
        'add_to_cart_error' => ["Message si l'ajout au panier échoue", 'textarea'],
    ],
    'cart' => [
        'title' => ['Titre principal', 'text'],
        'empty_message' => ['Message panier vide', 'textarea'],
        'empty_button' => ['Bouton panier vide', 'text'],
        'onsite_badge' => ['Badge « domicile »', 'text'],
        'workshop_badge' => ['Badge « atelier »', 'text'],
        'discount_label' => ['Libellé de la remise groupée', 'text'],
        'total_label' => ['Libellé du total', 'text'],
        'add_another_button' => ['Bouton Ajouter une autre prestation', 'text'],
        'checkout_button' => ['Bouton Finaliser ma réservation', 'text'],
    ],
    'intake' => [
        'title' => ['Titre principal', 'text'],
        'reassurance' => ['Texte de réassurance sous les questions', 'textarea'],
        'continue_button' => ['Texte du bouton Continuer', 'text'],
        'validation_error' => ['Message si un champ requis est vide', 'textarea'],
    ],
    'contact' => [
        'title' => ['Titre principal', 'text'],
        'first_name_label' => ['Libellé « Prénom »', 'text'],
        'last_name_label' => ['Libellé « Nom »', 'text'],
        'email_label' => ['Libellé « Email »', 'text'],
        'phone_label' => ['Libellé « Téléphone »', 'text'],
        'address_heading' => ["Titre de la section adresse", 'text'],
        'street_label' => ['Libellé « Rue »', 'text'],
        'number_label' => ['Libellé « Numéro »', 'text'],
        'postal_label' => ['Libellé « Code postal »', 'text'],
        'city_label' => ['Libellé « Ville »', 'text'],
        'privacy_reassurance' => ['Texte de confidentialité', 'textarea'],
        'consent_label' => ['Texte de la case à cocher CGV', 'textarea'],
        'continue_button' => ['Texte du bouton Choisir un créneau', 'text'],
        'required_error' => ['Message si prénom/email manquant', 'textarea'],
        'consent_error' => ['Message si la case CGV non cochée', 'textarea'],
    ],
    'slot' => [
        'title' => ['Titre principal', 'text'],
        'empty_onsite' => ['Message si aucun créneau à domicile', 'textarea'],
        'empty_workshop' => ['Message si aucun créneau atelier', 'textarea'],
        'onsite_heading' => ['Titre de la section domicile', 'text'],
        'workshop_heading' => ['Titre de la section atelier', 'text'],
        'sms_reassurance' => ['Texte de réassurance SMS', 'textarea'],
        'continue_button' => ['Texte du bouton Continuer', 'text'],
        'select_required_error' => ['Message si aucun créneau choisi', 'textarea'],
        'load_error' => ['Message si le chargement des disponibilités échoue', 'textarea'],
        'retry_button' => ['Bouton Réessayer', 'text'],
        'morning_label' => ['Libellé « Matin »', 'text'],
        'afternoon_label' => ['Libellé « Après-midi »', 'text'],
        'evening_label' => ['Libellé « Soir »', 'text'],
        'workshop_slot_prefix' => ['Texte avant l\'heure de dépôt (créneau atelier)', 'text'],
        'workshop_slot_reprise' => ['Texte avant l\'heure de reprise (créneau atelier)', 'text'],
    ],
    'recap' => [
        'title' => ['Titre principal', 'text'],
        'discount_label' => ['Libellé de la remise groupée', 'text'],
        'total_label' => ['Libellé du total', 'text'],
        'promo_label' => ['Libellé du code promo', 'text'],
        'promo_button' => ['Bouton Appliquer le code', 'text'],
        'cancellation_reassurance' => ["Texte de réassurance annulation/paiement", 'textarea'],
        'confirm_button' => ['Bouton Confirmer la demande', 'text'],
        'slot_taken_error' => ['Message si le créneau vient d\'être pris', 'textarea'],
    ],
    'done' => [
        'title' => ['Titre principal', 'text'],
        'message_prefix' => ['Texte avant la référence de commande', 'text'],
        'message_suffix' => ['Texte après la référence de commande', 'text'],
        'subtext' => ['Sous-texte (email/paiement)', 'textarea'],
        'contact_prefix' => ['Texte avant le numéro de téléphone de contact', 'text'],
        'contact_fallback_phone' => ['Numéro affiché si aucun téléphone en Réglages', 'text'],
        'restart_button' => ['Bouton Réserver une autre prestation', 'text'],
    ],
    'shared' => [
        'loading' => ['Texte de chargement', 'text'],
        'generic_error' => ['Message d\'erreur générique', 'text'],
        'out_of_zone_message' => ['Message hors zone de service', 'textarea'],
        'out_of_zone_fallback' => ['Repli si aucun contact configuré', 'text'],
    ],
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Textes du tunnel — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap">
        <p><a href="/admin/formulaire">← Formulaire</a></p>
        <?php if (!empty($data['flash'])): ?><p class="kn-alert kn-alert-ok"><?= $e($data['flash']) ?></p><?php endif; ?>

        <h1>Textes des étapes du tunnel</h1>
        <p class="kn-muted">
            Ces textes sont visibles immédiatement dans le tunnel de réservation dès l'enregistrement —
            laisser un champ vide revient au texte par défaut.
        </p>

        <form method="post" action="/admin/formulaire/textes">
            <?= $data['csrf'] ?>

            <?php foreach ($fieldMeta as $step => $fields): ?>
                <section class="kn-card" style="margin-top:16px;">
                    <details<?= $step === 'where' ? ' open' : '' ?>>
                        <summary><h2 style="display:inline;"><?= $e($stepLabels[$step] ?? $step) ?></h2></summary>
                        <div style="margin-top:12px;">
                            <?php foreach ($fields as $key => [$label, $type]): ?>
                                <div class="kn-field">
                                    <label for="<?= $step ?>__<?= $key ?>"><?= $e($label) ?></label>
                                    <?php if ($type === 'textarea'): ?>
                                        <textarea id="<?= $step ?>__<?= $key ?>" name="<?= $step ?>__<?= $key ?>" rows="2"
                                                  style="width:100%;padding:8px;border:1px solid var(--kn-line);border-radius:4px;font:inherit;"><?= $e($texts[$step][$key] ?? '') ?></textarea>
                                    <?php else: ?>
                                        <input type="text" id="<?= $step ?>__<?= $key ?>" name="<?= $step ?>__<?= $key ?>"
                                               value="<?= $e($texts[$step][$key] ?? '') ?>" style="width:100%;">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </section>
            <?php endforeach; ?>

            <button type="submit" class="kn-btn kn-btn-primary" style="margin-top:16px;">Enregistrer</button>
        </form>
    </main>
</body>
</html>
