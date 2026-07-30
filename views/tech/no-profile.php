<?php
/**
 * Vue : compte technicien connecté mais rattaché à aucune fiche technicien.
 *
 * Remplace l'erreur brute « 403 Compte technicien introuvable » : sans fiche
 * (technicians.user_id), l'app terrain n'a ni planning ni compétences à
 * afficher. Le rattachement se fait côté back-office.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#4A63E7">
    <title>Compte non rattaché — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
    <style>
        body { max-width: 520px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="kn-tech-head">
        <div><strong><?= $e($data['user_name'] ?? 'Bonjour') ?></strong></div>
        <a class="kn-btn kn-btn-ghost" href="/admin/deconnexion" style="min-height:40px;">Quitter</a>
    </div>

    <main style="padding:16px;">
        <div class="kn-card">
            <h1 style="margin-top:0;">Votre app terrain n'est pas encore activée</h1>
            <p>
                Votre compte fonctionne, mais il n'est rattaché à aucune <strong>fiche technicien</strong>.
                C'est cette fiche qui porte vos compétences, vos zones et vos disponibilités : sans elle,
                aucun rendez-vous ne peut vous être affiché.
            </p>
            <p class="kn-muted">
                Demandez à un administrateur d'ouvrir <em>Utilisateurs → votre compte</em> dans le back-office
                et de créer ou rattacher votre fiche technicien. L'opération prend quelques secondes ;
                reconnectez-vous ensuite.
            </p>
            <a class="kn-btn kn-btn-primary" href="/tech">Réessayer</a>
        </div>
    </main>
</body>
</html>
