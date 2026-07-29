<?php
/**
 * Vue : simulateur de prix du back-office.
 * Config live d'une prestation → devis détaillé (le « devis vivant » du brief).
 * Calcul par appel JSON, sans rechargement.
 *
 * @var array<string,mixed> $data
 * @var callable $e
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Simulateur de prix — Keepnew</title>
    <link rel="stylesheet" href="/assets/design-tokens.css">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
    <?php include __DIR__ . '/_nav.php'; ?>

    <main class="kn-wrap">
        <h1>Simulateur de prix</h1>
        <p class="kn-muted">Choisissez une configuration : le prix et la durée se recalculent en direct, avec le détail ligne par ligne.</p>

        <div class="kn-grid kn-grid-2">
            <section class="kn-card">
                <h2>Configuration</h2>

                <div class="kn-field">
                    <label for="service">Prestation</label>
                    <select id="service">
                        <option value="">— Choisir —</option>
                        <?php foreach ($data['services'] as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= $e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="kn-field">
                    <label for="mode">Mode</label>
                    <select id="mode"></select>
                </div>

                <div class="kn-field" id="variant-field" hidden>
                    <label for="variant">Variante</label>
                    <select id="variant"></select>
                </div>

                <div class="kn-field">
                    <label for="quantity">Quantité</label>
                    <input type="number" id="quantity" min="1" value="1">
                </div>

                <fieldset id="extras-field" style="border:1px solid var(--kn-line);border-radius:6px;padding:16px;" hidden>
                    <legend>Extras</legend>
                    <div id="extras"></div>
                </fieldset>
            </section>

            <section class="kn-card">
                <h2>Devis</h2>
                <div id="quote" class="kn-ticket" aria-live="polite">
                    <p class="kn-muted">Sélectionnez une prestation pour lancer le calcul.</p>
                </div>
            </section>
        </div>
    </main>

    <script>
    (function () {
        "use strict";
        const CSRF = <?= json_encode($data['csrf_token'], JSON_UNESCAPED_SLASHES) ?>;
        const $ = (id) => document.getElementById(id);
        const svc = $("service"), mode = $("mode"), variantField = $("variant-field"),
              variant = $("variant"), qty = $("quantity"),
              extrasField = $("extras-field"), extras = $("extras"), quote = $("quote");

        const MODE_LABEL = { onsite: "À domicile", workshop: "Atelier" };

        async function loadConfig(id) {
            const res = await fetch("/admin/simulateur/service/" + encodeURIComponent(id), { headers: { "Accept": "application/json" } });
            if (!res.ok) return null;
            return res.json();
        }

        function fillSelect(el, items, valueKey, labelKey) {
            el.innerHTML = "";
            items.forEach((it) => {
                const opt = document.createElement("option");
                opt.value = it[valueKey];
                opt.textContent = it[labelKey];
                el.appendChild(opt);
            });
        }

        svc.addEventListener("change", async () => {
            quote.innerHTML = '<p class="kn-muted">Calcul…</p>';
            if (!svc.value) { quote.innerHTML = '<p class="kn-muted">Sélectionnez une prestation.</p>'; return; }
            const cfg = await loadConfig(svc.value);
            if (!cfg) { quote.innerHTML = '<p class="kn-alert kn-alert-error">Prestation introuvable.</p>'; return; }

            fillSelect(mode, cfg.modes.map(m => ({ v: m.mode, l: MODE_LABEL[m.mode] || m.mode })), "v", "l");

            if (cfg.variants.length) {
                fillSelect(variant, cfg.variants, "id", "label");
                variantField.hidden = false;
            } else { variantField.hidden = true; variant.innerHTML = ""; }

            extras.innerHTML = "";
            if (cfg.extras.length) {
                cfg.extras.forEach((x) => {
                    const id = "extra_" + x.id;
                    const wrap = document.createElement("label");
                    wrap.className = "kn-check";
                    const input = document.createElement("input");
                    input.type = "checkbox"; input.value = x.id; input.className = "extra-cb";
                    input.id = id;
                    wrap.appendChild(input);
                    wrap.appendChild(document.createTextNode(" " + x.label + (x.selection_type === "radio" ? " (exclusif)" : "")));
                    extras.appendChild(wrap);
                    input.addEventListener("change", () => {
                        // Exclusivité : un extra « radio » décoche les autres de son groupe.
                        if (x.selection_type === "radio" && input.checked) {
                            document.querySelectorAll(".extra-cb").forEach((cb) => {
                                const other = cfg.extras.find(z => String(z.id) === cb.value);
                                if (other && other.exclusive_group === x.exclusive_group && cb !== input) cb.checked = false;
                            });
                        }
                        recompute();
                    });
                });
                extrasField.hidden = false;
            } else { extrasField.hidden = true; }

            recompute();
        });

        [mode, variant, qty].forEach((el) => el.addEventListener("change", recompute));
        qty.addEventListener("input", recompute);

        async function recompute() {
            if (!svc.value || !mode.value) return;
            const extraIds = Array.from(document.querySelectorAll(".extra-cb:checked")).map(cb => parseInt(cb.value, 10));
            const payload = {
                service_id: parseInt(svc.value, 10),
                mode: mode.value,
                variant_id: (!variantField.hidden && variant.value) ? parseInt(variant.value, 10) : 0,
                extra_ids: extraIds,
                quantity: Math.max(1, parseInt(qty.value || "1", 10)),
            };
            const res = await fetch("/admin/simulateur/calcul", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF, "Accept": "application/json" },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!res.ok) { quote.innerHTML = '<p class="kn-alert kn-alert-error">' + (data.error || "Erreur de calcul") + '</p>'; return; }
            render(data);
        }

        function render(q) {
            let html = "";
            q.components.forEach((c) => {
                const sign = c.price_cents < 0 ? "−" : "";
                const abs = Math.abs(c.price_cents);
                html += '<div class="row"><span>' + escapeHtml(c.label)
                     + ' <span class="comp">' + c.duration_min + ' min</span></span>'
                     + '<span>' + sign + euro(abs) + '</span></div>';
            });
            html += '<div class="row"><span>Sous-total HTVA</span><span>' + q.htva_formatted + '</span></div>';
            html += '<div class="row"><span class="comp">TVA ' + (q.vat_rate_bp / 100) + ' %</span><span class="comp">' + q.vat_formatted + '</span></div>';
            html += '<div class="row total"><span>Total TVAC</span><span>' + q.tvac_formatted + '</span></div>';
            html += '<p class="kn-muted" style="margin-top:12px;">Durée estimée : ' + q.total_duration_min + ' min'
                 + (q.occupancy_duration_min ? ' · immobilisation poste ' + q.occupancy_duration_min + ' min' : '') + '</p>';
            quote.innerHTML = html;
        }

        function euro(cents) { return (cents / 100).toFixed(2).replace(".", ",") + " €"; }
        function escapeHtml(s) { const d = document.createElement("div"); d.textContent = s; return d.innerHTML; }
    })();
    </script>
</body>
</html>
