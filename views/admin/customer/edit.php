<?php
/**
 * Vue : création / édition d'un client.
 * $data['customer'] === null → création.
 * @var array<string,mixed> $data
 * @var callable $e
 */
$c = $data['customer'];
$isNew = $c === null;
$type = $c['type'] ?? 'b2c';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'Nouveau client' : 'Modifier le client' ?> — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/_nav.php'; ?>

    <main class="kn-wrap kn-wrap-narrow">
        <p><a href="<?= $isNew ? '/admin/clients' : '/admin/client/' . (int) $c['id'] ?>">← Retour</a></p>
        <h1><?= $isNew ? 'Nouveau client' : 'Modifier la fiche' ?></h1>

        <?php if (!empty($data['error'])): ?><p class="kn-alert kn-alert-error"><?= $e($data['error']) ?></p><?php endif; ?>

        <form method="post" action="<?= $isNew ? '/admin/clients' : '/admin/client/' . (int) $c['id'] ?>" class="kn-card">
            <?= $data['csrf'] ?>

            <div class="kn-field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="b2c" <?= $type === 'b2c' ? 'selected' : '' ?>>Particulier (B2C)</option>
                    <option value="b2b" <?= $type === 'b2b' ? 'selected' : '' ?>>Professionnel (B2B)</option>
                </select>
            </div>

            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="first_name">Prénom</label><input type="text" id="first_name" name="first_name" value="<?= $e($c['first_name'] ?? '') ?>"></div>
                <div class="kn-field"><label for="last_name">Nom</label><input type="text" id="last_name" name="last_name" value="<?= $e($c['last_name'] ?? '') ?>"></div>
            </div>

            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="company_name">Société (B2B)</label><input type="text" id="company_name" name="company_name" value="<?= $e($c['company_name'] ?? '') ?>"></div>
                <div class="kn-field"><label for="vat_number">N° TVA (B2B)</label><input type="text" id="vat_number" name="vat_number" value="<?= $e($c['vat_number'] ?? '') ?>" placeholder="BE0123456789"></div>
            </div>

            <div class="kn-grid kn-grid-2">
                <div class="kn-field"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= $e($c['email'] ?? '') ?>" required></div>
                <div class="kn-field"><label for="phone">Téléphone</label><input type="text" id="phone" name="phone" value="<?= $e($c['phone'] ?? '') ?>" inputmode="tel"></div>
            </div>

            <button type="submit" class="kn-btn kn-btn-primary"><?= $isNew ? 'Créer le client' : 'Enregistrer' ?></button>
        </form>
    </main>
</body>
</html>
