/**
 * Réordonnancement d'une liste, utilisable au doigt et au clavier.
 *
 * Le catalogue proposait « Glissez-déposez les lignes pour réordonner » :
 * un drag & drop HTML5 natif, qui ne déclenche aucun événement au toucher et
 * n'offre aucune prise au clavier. Sur tablette ou téléphone, réordonner était
 * tout simplement impossible, et l'enregistrement se faisait sans le moindre
 * retour — si l'appel échouait, l'écran montrait le nouvel ordre et la base
 * gardait l'ancien.
 *
 * Ici : deux boutons « monter / descendre » sur chaque ligne (donc tactiles et
 * accessibles au clavier), le glisser-déposer conservé en plus pour la souris,
 * et un message explicite en cas d'échec.
 *
 * Usage — sur le conteneur :
 *     data-reorder="/admin/catalogue/services/ordre"
 *     data-reorder-item="tr"        (sélecteur des éléments déplaçables)
 *     data-reorder-label="prestation"
 * Chaque élément porte data-id. Le jeton CSRF est lu dans un <input> de la page.
 */
(function () {
    "use strict";

    function csrfToken() {
        var input = document.querySelector('input[name="_csrf"]');
        return input ? input.value : "";
    }

    function announce(container, message, ok) {
        var zone = container._knStatus;
        if (!zone) {
            zone = document.createElement("p");
            zone.className = "kn-reorder-status";
            zone.setAttribute("role", "status");
            zone.setAttribute("aria-live", "polite");
            container.parentNode.insertBefore(zone, container.nextSibling);
            container._knStatus = zone;
        }
        zone.textContent = message;
        zone.classList.toggle("is-error", !ok);
    }

    function setup(container) {
        var endpoint = container.dataset.reorder;
        var itemSel = container.dataset.reorderItem || "li";
        var label = container.dataset.reorderLabel || "élément";

        function items() {
            return Array.prototype.filter.call(
                container.querySelectorAll(itemSel),
                function (el) {
                    // Les listes imbriquées (arborescence de catégories) ne
                    // réordonnent qu'à leur propre niveau.
                    return el.parentNode === container && el.dataset.id;
                }
            );
        }

        function save() {
            var order = items().map(function (el) {
                return parseInt(el.dataset.id, 10);
            });
            announce(container, "Enregistrement…", true);

            fetch(endpoint, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrfToken(),
                    Accept: "application/json",
                },
                body: JSON.stringify({ order: order }),
            })
                .then(function (r) {
                    if (!r.ok) throw new Error(r.status);
                    announce(container, "Ordre enregistré.", true);
                })
                .catch(function () {
                    announce(
                        container,
                        "Le nouvel ordre n'a PAS été enregistré. Rechargez la page et réessayez.",
                        false
                    );
                });
        }

        function move(el, delta) {
            var list = items();
            var i = list.indexOf(el);
            var j = i + delta;
            if (j < 0 || j >= list.length) return;
            if (delta < 0) {
                container.insertBefore(el, list[j]);
            } else {
                container.insertBefore(list[j], el);
            }
            refresh();
            save();
            // Le focus suit la ligne déplacée, sinon le clavier le perd.
            var btn = el.querySelector(delta < 0 ? "[data-move='up']" : "[data-move='down']");
            if (btn) btn.focus();
        }

        // Désactive les flèches aux extrémités : un bouton qui ne fait rien
        // vaut moins qu'un bouton visiblement inactif.
        function refresh() {
            var list = items();
            list.forEach(function (el, i) {
                var up = el.querySelector("[data-move='up']");
                var down = el.querySelector("[data-move='down']");
                if (up) up.disabled = i === 0;
                if (down) down.disabled = i === list.length - 1;
            });
        }

        items().forEach(function (el) {
            if (el.querySelector("[data-move]")) return;
            var name = (el.dataset.reorderName || el.textContent.trim().split("\n")[0] || label).slice(0, 40);
            var box = document.createElement(container.dataset.reorderItem === "tr" ? "td" : "span");
            box.className = "kn-reorder-controls";
            box.innerHTML =
                '<button type="button" class="kn-reorder-btn" data-move="up" aria-label="Monter : ' +
                name.replace(/"/g, "") +
                '">▲</button>' +
                '<button type="button" class="kn-reorder-btn" data-move="down" aria-label="Descendre : ' +
                name.replace(/"/g, "") +
                '">▼</button>';
            if (container.dataset.reorderItem === "tr") {
                el.insertBefore(box, el.firstElementChild);
            } else {
                el.insertBefore(box, el.firstChild);
            }
        });

        container.addEventListener("click", function (ev) {
            var btn = ev.target.closest("[data-move]");
            if (!btn) return;
            var el = btn.closest(itemSel);
            if (el) move(el, btn.dataset.move === "up" ? -1 : 1);
        });

        // Glisser-déposer conservé pour la souris, en complément.
        var dragged = null;
        container.addEventListener("dragstart", function (ev) {
            dragged = ev.target.closest(itemSel);
            if (ev.dataTransfer) ev.dataTransfer.effectAllowed = "move";
        });
        container.addEventListener("dragover", function (ev) {
            if (!dragged) return;
            ev.preventDefault();
            var row = ev.target.closest(itemSel);
            if (!row || row === dragged || row.parentNode !== container) return;
            var rect = row.getBoundingClientRect();
            var after = ev.clientY - rect.top > rect.height / 2;
            container.insertBefore(dragged, after ? row.nextSibling : row);
        });
        container.addEventListener("drop", function (ev) {
            if (!dragged) return;
            ev.preventDefault();
            dragged = null;
            refresh();
            save();
        });

        refresh();
    }

    document.querySelectorAll("[data-reorder]").forEach(setup);
})();
