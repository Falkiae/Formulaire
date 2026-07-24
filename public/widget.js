/*!
 * Keepnew Booking — widget public autonome (vanilla JS, zéro dépendance).
 *
 * Embarquement :
 *   <div id="keepnew-booking" data-api="https://booking.keepnew.be"></div>
 *   <script src="https://booking.keepnew.be/widget.js" async></script>
 *
 * Rendu en Shadow DOM (styles isolés du site hôte). Consomme l'API REST
 * (/api/*). Tunnel mobile-first : Où · Quoi · Détails · Panier · Questions ·
 * Coordonnées · Rendez-vous · Récapitulatif · Confirmation. Devis « vivant »
 * collant. Reprise de session via localStorage.
 *
 * Navigation : chaque étape est un panneau plein écran (dans un conteneur de
 * défilement interne et autonome — pas le scroll de la page hôte) empilé au
 * fur et à mesure ; on avance en cliquant (comme avant) ou en faisant défiler
 * / glissant entre panneaux déjà atteints, avec un point d'ancrage par étape.
 *
 * Voix : vouvoiement chaleureux, libellés d'action explicites, jamais de jargon.
 */
(function () {
  "use strict";

  // --- Résolution de la base API --------------------------------------------
  var mount = document.getElementById("keepnew-booking");
  if (!mount) return;
  var current = document.currentScript;
  var apiBase =
    mount.getAttribute("data-api") ||
    (current && current.src ? new URL(current.src).origin : "") ||
    "";
  var API = apiBase.replace(/\/$/, "") + "/api";
  var STORAGE_KEY = "kn_booking_state";

  // --- État -----------------------------------------------------------------
  var state = load() || {
    step: "where",
    token: null,
    postal: "",
    mode: null, // onsite | workshop
    categoryId: null,
    serviceId: null,
    variantId: null,
    extraIds: [],
    quantity: 1,
    cart: null, // snapshot
    answers: {},
    customer: {},
    address: {},
    slots: {}, // { onsite:{...}, workshop:{...} }
    availability: null,
    booking: null,
  };

  var catalog = null; // { categories, services }

  // --- Utilitaires ----------------------------------------------------------
  function save() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (e) {}
  }
  function load() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY));
    } catch (e) {
      return null;
    }
  }
  function reset() {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
  }
  function euro(cents) {
    return (cents / 100).toFixed(2).replace(".", ",") + " €";
  }
  // Construit l'URL publique d'une image du catalogue (servie sous /uploads).
  function imgUrl(p) {
    return p ? apiBase.replace(/\/$/, "") + "/uploads/" + p : null;
  }
  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ "Content-Type": "application/json", Accept: "application/json" }, opts.headers || {});
    if (opts.body && typeof opts.body !== "string") opts.body = JSON.stringify(opts.body);
    return fetch(API + path, opts).then(function (r) {
      return r.json().then(function (d) {
        if (!r.ok) throw Object.assign(new Error(d.error || "Erreur"), { data: d, status: r.status });
        return d;
      });
    });
  }
  function el(html) {
    var t = document.createElement("template");
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }
  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s == null ? "" : String(s);
    return d.innerHTML;
  }
  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  // --- Shadow DOM & styles ---------------------------------------------------
  var shadow = mount.attachShadow ? mount.attachShadow({ mode: "open" }) : mount;
  var root = document.createElement("div");
  root.className = "kn";
  var style = document.createElement("style");
  style.textContent = CSS();
  shadow.appendChild(style);
  shadow.appendChild(root);

  // Étapes nommées (barre de progression + dispatch de rendu).
  var STEPS = [
    { key: "where", label: "Où" },
    { key: "what", label: "Quoi" },
    { key: "details", label: "Détails" },
    { key: "cart", label: "Panier" },
    { key: "intake", label: "Questions" },
    { key: "contact", label: "Coordonnées" },
    { key: "slot", label: "Rendez-vous" },
    { key: "recap", label: "Récapitulatif" },
    { key: "done", label: "Confirmé" },
  ];

  // Éléments persistants du « chrome » (montés une fois par mountShell()).
  var scroller, progressFillEl, progressLabelEl, quoteBarSlot, stepObserver;

  // --- Montage du chrome (barre de progression + scroller + panier) ---------
  function mountShell() {
    var progress = el('<div class="kn-progress" role="navigation" aria-label="Étapes"></div>');
    var track = el('<div class="kn-progress-track"></div>');
    progressFillEl = el('<div class="kn-progress-fill"></div>');
    track.appendChild(progressFillEl);
    progress.appendChild(track);
    progressLabelEl = el('<div class="kn-progress-label"></div>');
    progress.appendChild(progressLabelEl);
    root.appendChild(progress);

    scroller = el('<div class="kn-scroller"></div>');
    root.appendChild(scroller);

    quoteBarSlot = el('<div class="kn-quotebar-slot"></div>');
    root.appendChild(quoteBarSlot);

    setupStepObserver();
  }

  // Suit le panneau le plus visible pendant un défilement manuel : met à jour
  // l'étape courante + la barre de progression sans jamais toucher au DOM des
  // panneaux (défilement passif = non destructif, contrairement à goto()).
  function setupStepObserver() {
    if (!("IntersectionObserver" in window)) return;
    var saveTimer = null;
    stepObserver = new IntersectionObserver(
      function (entries) {
        var best = null;
        entries.forEach(function (entry) {
          if (entry.isIntersecting && (!best || entry.intersectionRatio > best.intersectionRatio)) {
            best = entry;
          }
        });
        if (best && best.target.dataset.step) {
          state.step = best.target.dataset.step;
          updateProgressBar(state.step);
          // Persistance différée (pas à chaque callback pendant un flick rapide).
          clearTimeout(saveTimer);
          saveTimer = setTimeout(save, 400);
        }
      },
      { root: scroller, threshold: [0.5] }
    );
  }

  function updateProgressBar(step) {
    var idx = STEPS.findIndex(function (s) {
      return s.key === step;
    });
    var safeIdx = idx < 0 ? 0 : idx;
    var pct = Math.round(((safeIdx + 1) / STEPS.length) * 100);
    progressFillEl.style.width = pct + "%";
    progressLabelEl.textContent = "Étape " + (safeIdx + 1) + "/" + STEPS.length + " · " + STEPS[safeIdx].label;
  }

  function updateQuoteBar() {
    quoteBarSlot.innerHTML = "";
    if (state.cart && state.cart.item_count > 0 && state.step !== "done") {
      quoteBarSlot.appendChild(quoteBar());
    }
  }

  // Dispatch d'étape : construit le contenu d'un step dans le panneau fourni.
  function renderStepInto(panel, step) {
    if (step === "where") renderWhere(panel);
    else if (step === "what") renderWhat(panel);
    else if (step === "details") renderDetails(panel);
    else if (step === "cart") renderCart(panel);
    else if (step === "intake") renderIntake(panel);
    else if (step === "contact") renderContact(panel);
    else if (step === "slot") renderSlot(panel);
    else if (step === "recap") renderRecap(panel);
    else if (step === "done") renderDone(panel);
  }

  function createPanel(step) {
    var panel = el('<section class="kn-step-panel" data-step="' + step + '" tabindex="-1"></section>');
    scroller.appendChild(panel);
    if (stepObserver) stepObserver.observe(panel);
    return panel;
  }

  function findPanel(step) {
    return (
      Array.prototype.filter.call(scroller.children, function (p) {
        return p.dataset.step === step;
      })[0] || null
    );
  }

  function scrollToPanel(panel) {
    panel.scrollIntoView({ behavior: prefersReducedMotion() ? "auto" : "smooth", block: "start" });
  }

  // Rend le focus clavier au panneau une fois le défilement stabilisé
  // (uniquement après une navigation programmatique via goto(), jamais après
  // un défilement manuel — on ne vole pas le focus pendant une consultation).
  function focusPanelWhenSettled(panel) {
    var done = false;
    function doFocus() {
      if (done) return;
      done = true;
      panel.focus({ preventScroll: true });
    }
    if ("onscrollend" in scroller) {
      scroller.addEventListener("scrollend", doFocus, { once: true });
    }
    setTimeout(doFocus, 500);
  }

  // Retire du DOM tous les panneaux postérieurs à `step` (navigation arrière
  // délibérée). Les étapes asynchrones (cart/slot/recap) refont déjà un appel
  // API à chaque exécution : rien à mettre en cache, la troncature est gratuite.
  function truncateAfter(step) {
    var idx = STEPS.findIndex(function (s) {
      return s.key === step;
    });
    Array.prototype.slice.call(scroller.children).forEach(function (panel) {
      var pIdx = STEPS.findIndex(function (s) {
        return s.key === panel.dataset.step;
      });
      if (pIdx > idx) {
        if (stepObserver) stepObserver.unobserve(panel);
        panel.remove();
      }
    });
  }

  // --- Navigation entre étapes -------------------------------------------
  // Seul point d'appel externe (~10 endroits, signature inchangée). Tout
  // appel à goto() est un clic délibéré (le défilement passif à la souris/au
  // doigt ne passe jamais par ici) : il peut donc trancher sans ambiguïté
  // entre 3 cas selon la position du panneau ciblé par rapport au plus avancé.
  function goto(step) {
    var existing = findPanel(step);

    if (!existing) {
      // Cas A — étape jamais atteinte : nouveau panneau, on avance.
      var panel = createPanel(step);
      renderStepInto(panel, step);
      state.step = step;
      save();
      scrollToPanel(panel);
      focusPanelWhenSettled(panel);
    } else {
      // Cas B — panneau déjà créé : soit auto-rafraîchissement sur place
      // (ex. suppression d'une ligne panier), soit l'utilisateur a défilé en
      // arrière puis fait un nouveau choix dans un panneau antérieur (ex.
      // reprendre une autre prestation depuis "what"). Toujours re-rendu :
      // un panneau peut dépendre d'un state.* modifié depuis sa création (le
      // mode choisi à "where" change ce que "what" doit proposer).
      // truncateAfter() est un no-op naturel si `step` est déjà le panneau
      // le plus avancé.
      truncateAfter(step);
      renderStepInto(clear(existing), step);
      state.step = step;
      save();
      scrollToPanel(existing);
      focusPanelWhenSettled(existing);
    }
    updateProgressBar(state.step);
    updateQuoteBar();
  }

  // Reconstruit l'historique des panneaux au chargement (reprise localStorage) :
  // rejoue chaque étape de "where" jusqu'à l'étape sauvegardée, pour que le
  // défilement arrière fonctionne aussi après un rechargement de page.
  function replaySession() {
    var targetIdx = STEPS.findIndex(function (s) {
      return s.key === state.step;
    });
    if (targetIdx < 0) targetIdx = 0;
    var lastPanel = null;
    for (var i = 0; i <= targetIdx; i++) {
      var panel = createPanel(STEPS[i].key);
      renderStepInto(panel, STEPS[i].key);
      lastPanel = panel;
    }
    updateProgressBar(state.step);
    updateQuoteBar();
    if (lastPanel) {
      requestAnimationFrame(function () {
        lastPanel.scrollIntoView({ behavior: "auto", block: "start" });
      });
    }
  }

  // --- Étape 1 : OÙ (mode d'abord ; code postal seulement pour le domicile) --
  function renderWhere(body) {
    body.appendChild(el('<h2 class="kn-h">Où souhaitez-vous être nettoyé ?</h2>'));

    var cards = el('<div class="kn-cards"></div>');
    var onsiteCard = choiceCard("🏠", "Je veux qu'on vienne chez moi", "Un technicien se déplace à votre adresse.", function () {
      // Retour visuel immédiat : state.mode n'est posé qu'au clic sur
      // Continuer, mais la sélection doit déjà être visible pendant la
      // saisie du code postal.
      onsiteCard.classList.add("on");
      workshopCard.classList.remove("on");
      showPostal();
    }, state.mode === "onsite");
    var workshopCard = choiceCard("🔧", "Je viens à l'atelier", "Vous déposez, nous nettoyons. Souvent moins cher.", function () {
      onsiteCard.classList.remove("on");
      workshopCard.classList.add("on");
      pickMode("workshop");
    }, state.mode === "workshop");
    cards.appendChild(onsiteCard);
    cards.appendChild(workshopCard);
    body.appendChild(cards);

    // Le code postal ne concerne que le domicile (résolution de zone) : il
    // n'est révélé qu'après le choix « chez moi », jamais pour l'atelier.
    var postalWrap = el('<div class="kn-postal-wrap" hidden></div>');
    var field = el(
      '<div class="kn-field"><label for="kn-postal">Votre code postal</label>' +
        '<input id="kn-postal" inputmode="numeric" autocomplete="postal-code" maxlength="4" value="' +
        esc(state.postal) +
        '" placeholder="Ex. 4000"></div>'
    );
    postalWrap.appendChild(field);
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">Continuer</button>');
    cont.addEventListener("click", function () {
      pickMode("onsite");
    });
    postalWrap.appendChild(cont);
    body.appendChild(postalWrap);

    var input = field.querySelector("input");
    input.addEventListener("input", function () {
      state.postal = input.value.replace(/\D/g, "").slice(0, 4);
    });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") pickMode("onsite");
    });

    function showPostal() {
      postalWrap.hidden = false;
      input.focus();
    }
    if (state.mode === "onsite") showPostal();

    body.appendChild(reassure("Oui, nous intervenons à Liège et dans un rayon de 25 km."));
  }
  function pickMode(mode) {
    // Le code postal n'est requis que pour le domicile (zone) ; pas pour l'atelier.
    if (mode === "onsite" && (!state.postal || state.postal.length < 4)) {
      flash("Indiquez d'abord votre code postal.");
      return;
    }
    state.mode = mode;
    ensureCart().then(function () {
      setTimeout(function () {
        goto("what");
      }, 300);
    });
  }

  // --- Étape 2 : QUOI (catégorie → service) ---------------------------------
  function renderWhat(body) {
    body.appendChild(el('<h2 class="kn-h">Quelle prestation ?</h2>'));
    if (!catalog) {
      body.appendChild(loading());
      api("/catalog").then(function (d) {
        catalog = d;
        renderStepInto(clear(body), state.step);
      });
      return;
    }
    var cards = el('<div class="kn-cards"></div>');
    if (!state.categoryId) {
      catalog.categories
        .filter(function (cat) {
          return categoryHasCompatibleService(cat);
        })
        .forEach(function (cat) {
          cards.appendChild(
            choiceCard("📦", cat.name, cat.description || "", function () {
              state.categoryId = cat.id;
              // Descend d'un niveau si sous-catégories, sinon services.
              renderStepInto(clear(body), state.step);
            }, false, imgUrl(cat.image_path))
          );
        });
    } else {
      var cat = findCategory(state.categoryId);
      (cat && cat.children && cat.children.length ? cat.children : [cat]).forEach(function (c) {
        catalog.services
          .filter(function (s) {
            return s.category_id === c.id && serviceSupportsMode(s, state.mode);
          })
          .forEach(function (svc) {
            cards.appendChild(
              choiceCard("🧽", svc.name, svc.short_description || "", function () {
                state.serviceId = svc.id;
                state.variantId = null;
                state.extraIds = [];
                setTimeout(function () {
                  goto("details");
                }, 300);
              }, false, imgUrl(svc.image_path))
            );
          });
      });
      body.appendChild(backLink(function () {
        state.categoryId = null;
        renderStepInto(clear(body), state.step);
      }));
    }
    body.appendChild(cards);
  }
  // Une prestation sans info de mode (API pas encore à jour) est considérée
  // compatible par défaut, pour ne jamais masquer le catalogue par erreur.
  function serviceSupportsMode(svc, mode) {
    return !svc.modes || svc.modes.length === 0 || svc.modes.indexOf(mode) >= 0;
  }
  // Une catégorie n'est affichée que si elle (ou l'une de ses sous-catégories)
  // contient au moins une prestation compatible avec le mode choisi.
  function categoryHasCompatibleService(cat) {
    var direct = catalog.services.some(function (s) {
      return s.category_id === cat.id && serviceSupportsMode(s, state.mode);
    });
    if (direct) return true;
    return (cat.children || []).some(categoryHasCompatibleService);
  }
  function findCategory(id) {
    var found = null;
    (function walk(nodes) {
      nodes.forEach(function (n) {
        if (n.id === id) found = n;
        if (n.children) walk(n.children);
      });
    })(catalog.categories);
    return found;
  }

  // --- Étape 3 : DÉTAILS (variante + extras + prix live) --------------------
  function renderDetails(body) {
    body.appendChild(el('<h2 class="kn-h">Configurez votre prestation</h2>'));
    var cfg = state._serviceConfig;
    if (!cfg || cfg.id !== state.serviceId) {
      body.appendChild(loading());
      api("/services/" + state.serviceId).then(function (d) {
        state._serviceConfig = d;
        renderStepInto(clear(body), state.step);
      });
      return;
    }

    // Variantes (choix unique → auto-avance non, car on continue à configurer).
    if (cfg.variants.length) {
      body.appendChild(el('<h3 class="kn-h3">Votre modèle</h3>'));
      var vcards = el('<div class="kn-cards kn-cards-sm"></div>');
      cfg.variants.forEach(function (v) {
        vcards.appendChild(
          choiceCard("", v.label, "", function () {
            state.variantId = v.id;
            renderDetails(clear(body));
          }, state.variantId === v.id)
        );
      });
      body.appendChild(vcards);
    }

    // Extras (choix multiple → jamais d'auto-avance).
    if (cfg.extras.length) {
      body.appendChild(el('<h3 class="kn-h3">Options</h3>'));
      var list = el('<div class="kn-extras"></div>');
      cfg.extras.forEach(function (x) {
        var checked = state.extraIds.indexOf(x.id) >= 0;
        var thumb = imgUrl(x.image_path)
          ? '<img class="kn-extra-img" src="' + esc(imgUrl(x.image_path)) + '" alt="" loading="lazy">'
          : "";
        var row = el(
          '<label class="kn-extra">' +
            thumb +
            '<span class="kn-extra-txt">' +
            esc(x.label) +
            ' <span class="kn-muted">+' +
            euro(x.price_cents) +
            "</span></span>" +
            '<input type="checkbox" ' +
            (checked ? "checked" : "") +
            "></label>"
        );
        row.querySelector("input").addEventListener("change", function (e) {
          toggleExtra(x, e.target.checked, cfg);
          livePrice(live);
        });
        list.appendChild(row);
      });
      body.appendChild(list);
    }

    body.appendChild(reassure("Prix ferme. Aucun supplément le jour de l'intervention."));

    var live = el('<div class="kn-live"></div>');
    body.appendChild(live);
    livePrice(live);

    var actions = el('<div class="kn-actions"></div>');
    var add = el('<button class="kn-btn kn-btn-primary" type="button">Ajouter au panier</button>');
    add.addEventListener("click", addToCart);
    actions.appendChild(add);
    body.appendChild(actions);
  }
  function toggleExtra(x, on, cfg) {
    if (on && x.selection_type === "radio" && x.exclusive_group) {
      // Exclusivité : retire les autres extras du même groupe.
      state.extraIds = state.extraIds.filter(function (id) {
        var other = cfg.extras.find(function (z) {
          return z.id === id;
        });
        return !(other && other.exclusive_group === x.exclusive_group);
      });
    }
    if (on) {
      if (state.extraIds.indexOf(x.id) < 0) state.extraIds.push(x.id);
    } else {
      state.extraIds = state.extraIds.filter(function (id) {
        return id !== x.id;
      });
    }
  }
  function livePrice(liveEl) {
    if (!liveEl) return;
    var line = {
      service_id: state.serviceId,
      mode: state.mode,
      variant_id: state.variantId || 0,
      extra_ids: state.extraIds,
      quantity: state.quantity,
    };
    api("/quote", { method: "POST", body: { lines: [line] } })
      .then(function (q) {
        liveEl.innerHTML =
          '<div class="kn-live-price">' +
          q.total_tvac_formatted +
          '</div><div class="kn-muted">TVAC · durée estimée ' +
          q.total_active_duration_min +
          " min</div>";
      })
      .catch(function (err) {
        liveEl.innerHTML =
          '<p class="kn-muted">' +
          esc((err && err.data && err.data.error) || "Indisponible dans ce mode.") +
          "</p>";
      });
  }
  function addToCart() {
    var body = {
      service_id: state.serviceId,
      mode: state.mode,
      variant_id: state.variantId || 0,
      extra_ids: state.extraIds,
      quantity: state.quantity,
    };
    ensureCart().then(function () {
      api("/cart/" + state.token + "/items", { method: "POST", body: body })
        .then(function (snap) {
          state.cart = snap;
          var p = snap.pricing;
          dl("add_to_cart", { currency: "EUR", value: p ? p.total_tvac_cents / 100 : undefined, items: cartItemsForTracking() });
          state.serviceId = null;
          state.variantId = null;
          state.extraIds = [];
          state._serviceConfig = null;
          goto("cart");
        })
        .catch(function (err) {
          flash((err && err.data && err.data.error) || "Impossible d'ajouter cette prestation au panier.");
        });
    });
  }

  // --- Étape 4 : PANIER ------------------------------------------------------
  function renderCart(body) {
    body.appendChild(el('<h2 class="kn-h">Votre devis</h2>'));
    refreshCart().then(function () {
      clear(body);
      body.appendChild(el('<h2 class="kn-h">Votre devis</h2>'));
      if (!state.cart || state.cart.item_count === 0) {
        body.appendChild(el('<p class="kn-muted">Votre panier est vide. Ajoutez une prestation pour commencer.</p>'));
        var add0 = el('<button class="kn-btn kn-btn-primary" type="button">Choisir une prestation</button>');
        add0.addEventListener("click", function () {
          goto("what");
        });
        body.appendChild(add0);
        return;
      }
      // Groupes par mode (domicile / atelier).
      var ticket = el('<div class="kn-ticket"></div>');
      state.cart.items.forEach(function (it) {
        var row = el(
          '<div class="kn-line"><div><strong>' +
            esc(it.label) +
            '</strong> <span class="kn-badge">' +
            (it.mode === "onsite" ? "À domicile" : "Atelier") +
            "</span><br><span class=\"kn-muted\">×" +
            it.quantity +
            " · " +
            it.unit_duration_min +
            " min</span></div>" +
            '<div class="kn-line-r"><span>' +
            euro(it.unit_price_cents * it.quantity) +
            '</span></div></div>'
        );
        var del = el('<button class="kn-link" type="button">Supprimer</button>');
        del.addEventListener("click", function () {
          api("/cart/" + state.token + "/items/" + it.id, { method: "DELETE" }).then(function (snap) {
            state.cart = snap;
            goto("cart");
          });
        });
        row.querySelector(".kn-line-r").appendChild(del);
        ticket.appendChild(row);
      });
      var p = state.cart.pricing;
      if (p) {
        if (p.cumul_discount_cents > 0) {
          ticket.appendChild(el('<div class="kn-line kn-ok"><span>Remise groupée</span><span>−' + euro(p.cumul_discount_cents) + "</span></div>"));
        }
        ticket.appendChild(el('<div class="kn-line"><span class="kn-muted">TVA 21 %</span><span class="kn-muted">' + p.vat_formatted + "</span></div>"));
        ticket.appendChild(el('<div class="kn-line kn-total"><span>Total TVAC</span><span>' + p.total_tvac_formatted + "</span></div>"));
      }
      body.appendChild(ticket);

      var actions = el('<div class="kn-actions"></div>');
      var more = el('<button class="kn-btn kn-btn-ghost" type="button">Ajouter une autre prestation</button>');
      more.addEventListener("click", function () {
        goto("what");
      });
      var cont = el('<button class="kn-btn kn-btn-primary" type="button">Finaliser ma réservation</button>');
      cont.addEventListener("click", function () {
        var p = state.cart && state.cart.pricing ? state.cart.pricing : null;
        dl("begin_checkout", { currency: "EUR", value: p ? p.total_tvac_cents / 100 : undefined, items: cartItemsForTracking() });
        goto("intake");
      });
      actions.appendChild(more);
      actions.appendChild(cont);
      body.appendChild(actions);
    });
  }

  // --- Étape 5 : QUESTIONS D'INTAKE (formulaire dynamique) ------------------
  function renderIntake(body) {
    body.appendChild(el('<h2 class="kn-h">Quelques précisions</h2>'));
    if (!state._form) {
      body.appendChild(loading());
      api("/form")
        .then(function (f) {
          state._form = f;
          renderStepInto(clear(body), state.step);
        })
        .catch(function () {
          state._form = { fields: [], conditions: [] };
          renderStepInto(clear(body), state.step);
        });
      return;
    }

    var hasOnsite = cartHasMode("onsite");
    var container = el("<div></div>");
    body.appendChild(container);
    renderFormFields(container, hasOnsite);

    body.appendChild(reassure("Si l'état diffère, on vous prévient avant de commencer. Vous restez libre de refuser."));
    var actions = el('<div class="kn-actions"></div>');
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">Continuer</button>');
    cont.addEventListener("click", function () {
      if (!validateForm(hasOnsite)) {
        flash("Merci de compléter les champs requis.");
        return;
      }
      goto("contact");
    });
    actions.appendChild(cont);
    body.appendChild(actions);
  }
  // Champs effectivement visibles/requis après application des conditions.
  function formState(hasOnsite) {
    var st = {};
    state._form.fields.forEach(function (f) {
      var onsiteOnly = f.config && f.config.onsite_only;
      st[f.field_key] = { field: f, visible: onsiteOnly ? hasOnsite : true, required: !!f.is_required };
    });
    var byId = {};
    state._form.fields.forEach(function (f) { byId[f.id] = f; });
    state._form.conditions.forEach(function (c) {
      var src = byId[c.source_field_id], tgt = byId[c.target_field_id];
      if (!src || !tgt) return;
      var val = state.answers[src.field_key];
      if (!condMatches(val, c.operator, c.compare_value)) return;
      var s = st[tgt.field_key];
      if (c.action === "show") s.visible = true;
      else if (c.action === "hide") s.visible = false;
      else if (c.action === "require") s.required = true;
      else if (c.action === "optional") s.required = false;
    });
    return st;
  }
  function condMatches(val, op, cmp) {
    switch (op) {
      case "eq": return String(val) === String(cmp);
      case "neq": return String(val) !== String(cmp);
      case "in": return String(cmp || "").split(",").map(function (s) { return s.trim(); }).indexOf(String(val)) >= 0;
      case "filled": return val != null && String(val).trim() !== "";
      case "empty": return val == null || String(val).trim() === "";
      default: return false;
    }
  }
  function renderFormFields(container, hasOnsite) {
    container.innerHTML = "";
    var st = formState(hasOnsite);
    state._form.fields.forEach(function (f) {
      if (!st[f.field_key].visible) return;
      var node = fieldNode(f, st[f.field_key].required, hasOnsite, container);
      if (node) container.appendChild(node);
    });
  }
  function fieldNode(f, required, hasOnsite, container) {
    var label = f.label + (required ? " *" : "");
    if (f.field_type === "radio" || f.field_type === "select" || f.field_type === "cards") {
      var wrap = el('<div class="kn-field"><label>' + esc(label) + "</label></div>");
      var row = el('<div class="kn-choice-row"></div>');
      (f.options || []).forEach(function (o) {
        var b = el('<button type="button" class="kn-chip ' + (state.answers[f.field_key] === o.value ? "on" : "") + '">' + esc(o.label) + "</button>");
        b.addEventListener("click", function () {
          state.answers[f.field_key] = o.value;
          renderFormFields(container, hasOnsite);
        });
        row.appendChild(b);
      });
      wrap.appendChild(row);
      return wrap;
    }
    if (f.field_type === "checkbox") {
      var w2 = el('<label class="kn-extra"><span>' + esc(label) + "</span><input type=\"checkbox\" " + (state.answers[f.field_key] === "yes" ? "checked" : "") + "></label>");
      w2.querySelector("input").addEventListener("change", function (e) { state.answers[f.field_key] = e.target.checked ? "yes" : "no"; });
      return w2;
    }
    if (f.field_type === "textarea") {
      var w3 = el('<div class="kn-field"><label>' + esc(label) + '</label><textarea rows="2" style="width:100%;min-height:64px;padding:8px;border:1px solid var(--line);border-radius:8px;font:inherit;">' + esc(state.answers[f.field_key] || "") + "</textarea></div>");
      w3.querySelector("textarea").addEventListener("input", function (e) { state.answers[f.field_key] = e.target.value; });
      return w3;
    }
    // text, number, date, consent, autres → input
    var type = f.field_type === "number" ? "number" : (f.field_type === "date" ? "date" : "text");
    var w4 = el('<div class="kn-field"><label>' + esc(label) + '</label><input type="' + type + '" value="' + esc(state.answers[f.field_key] || "") + '"></div>');
    w4.querySelector("input").addEventListener("input", function (e) { state.answers[f.field_key] = e.target.value; });
    return w4;
  }
  function validateForm(hasOnsite) {
    var st = formState(hasOnsite);
    return state._form.fields.every(function (f) {
      var m = st[f.field_key];
      if (!m.visible || !m.required) return true;
      var v = state.answers[f.field_key];
      return v != null && String(v).trim() !== "";
    });
  }

  // --- Étape 6 : COORDONNÉES -------------------------------------------------
  function renderContact(body) {
    body.appendChild(el('<h2 class="kn-h">Vos coordonnées</h2>'));
    var hasOnsite = cartHasMode("onsite");
    var fields = [
      ["first_name", "Prénom", "given-name", "text"],
      ["last_name", "Nom", "family-name", "text"],
      ["email", "Email", "email", "email"],
      ["phone", "Téléphone", "tel", "tel"],
    ];
    fields.forEach(function (f) {
      body.appendChild(textField("customer", f[0], f[1], f[2], f[3]));
    });
    if (hasOnsite) {
      body.appendChild(el('<h3 class="kn-h3">Adresse d\'intervention</h3>'));
      body.appendChild(textField("address", "street", "Rue", "address-line1", "text"));
      body.appendChild(textField("address", "number", "Numéro", "", "text"));
      if (!state.address.postal_code) state.address.postal_code = state.postal;
      body.appendChild(textField("address", "postal_code", "Code postal", "postal-code", "text"));
      body.appendChild(textField("address", "city", "Ville", "address-level2", "text"));
    }
    body.appendChild(reassure("Vos données servent uniquement à organiser votre rendez-vous. Conservées le temps légal. Voir notre politique de confidentialité."));
    var consent = el('<label class="kn-extra"><span>J\'accepte les conditions générales et la politique de confidentialité.</span><input type="checkbox" id="kn-consent"></label>');
    body.appendChild(consent);
    var actions = el('<div class="kn-actions"></div>');
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">Choisir un créneau</button>');
    cont.addEventListener("click", function () {
      if (!state.customer.email || !state.customer.first_name) {
        flash("Merci d'indiquer au moins votre prénom et votre email.");
        return;
      }
      if (!consent.querySelector("input").checked) {
        flash("Merci d'accepter les conditions pour continuer.");
        return;
      }
      state._consent = true;
      goto("slot");
    });
    actions.appendChild(cont);
    body.appendChild(actions);
  }
  function textField(bag, key, label, autocomplete, type) {
    var f = el(
      '<div class="kn-field"><label>' +
        esc(label) +
        '</label><input type="' +
        type +
        '" autocomplete="' +
        autocomplete +
        '" value="' +
        esc(state[bag][key] || "") +
        '"></div>'
    );
    f.querySelector("input").addEventListener("input", function (e) {
      state[bag][key] = e.target.value;
    });
    return f;
  }

  // --- Étape 7 : RENDEZ-VOUS -------------------------------------------------
  function renderSlot(body) {
    body.appendChild(el('<h2 class="kn-h">Choisissez votre créneau</h2>'));
    body.appendChild(loading());
    api("/availability", {
      method: "POST",
      body: { token: state.token, postal: state.address.postal_code || state.postal, days: 10 },
    }).then(function (av) {
      state.availability = av;
      clear(body);
      body.appendChild(el('<h2 class="kn-h">Choisissez votre créneau</h2>'));

      ["onsite", "workshop"].forEach(function (mode) {
        var block = av[mode];
        if (!block) return;
        if (block.status === "out_of_zone") {
          body.appendChild(outOfZoneMessage());
          return;
        }
        if (block.status !== "ok" || !block.slots || !block.slots.length) {
          body.appendChild(el('<p class="kn-muted">Aucun créneau ' + (mode === "onsite" ? "à domicile" : "atelier") + ' disponible sur la période.</p>'));
          return;
        }
        body.appendChild(el('<h3 class="kn-h3">' + (mode === "onsite" ? "À domicile" : "À l'atelier") + "</h3>"));
        body.appendChild(slotPicker(mode, block.slots));
      });

      body.appendChild(reassure("Vous recevez un SMS quand le technicien part vers chez vous."));
      var actions = el('<div class="kn-actions"></div>');
      var cont = el('<button class="kn-btn kn-btn-primary" type="button">Continuer</button>');
      cont.addEventListener("click", function () {
        var need = [];
        if (av.onsite && av.onsite.status === "ok") need.push("onsite");
        if (av.workshop && av.workshop.status === "ok") need.push("workshop");
        var ok = need.every(function (m) {
          return state.slots[m];
        });
        if (!ok) {
          flash("Merci de choisir un créneau.");
          return;
        }
        goto("recap");
      });
      actions.appendChild(cont);
      body.appendChild(actions);
    });
  }
  function slotPicker(mode, slots) {
    // Regroupe par date locale (JJ/MM/AAAA).
    var byDate = {};
    slots.forEach(function (s) {
      var d = s.start_local.split(" ")[0];
      (byDate[d] = byDate[d] || []).push(s);
    });
    var wrap = el('<div class="kn-slots"></div>');
    var dates = Object.keys(byDate);
    var strip = el('<div class="kn-dates"></div>');
    var listBox = el('<div class="kn-slot-list"></div>');
    dates.forEach(function (d, i) {
      var b = el('<button type="button" class="kn-date ' + (i === 0 ? "on" : "") + '">' + esc(d) + "</button>");
      b.addEventListener("click", function () {
        strip.querySelectorAll(".kn-date").forEach(function (x) {
          x.classList.remove("on");
        });
        b.classList.add("on");
        fillSlots(listBox, mode, byDate[d]);
      });
      strip.appendChild(b);
    });
    wrap.appendChild(strip);
    wrap.appendChild(listBox);
    fillSlots(listBox, mode, byDate[dates[0]]);
    return wrap;
  }
  function fillSlots(box, mode, slots) {
    box.innerHTML = "";
    slots.forEach(function (s) {
      var label = mode === "onsite" ? s.start_local.split(" ")[1] : "Dépôt " + s.start_local.split(" ")[1] + " · reprise ~" + s.pickup_local;
      var chosen = state.slots[mode] && state.slots[mode].start_utc === s.start_utc && state.slots[mode].technician_id === s.technician_id;
      var b = el('<button type="button" class="kn-slot ' + (chosen ? "on" : "") + '">' + esc(label) + "</button>");
      b.addEventListener("click", function () {
        state.slots[mode] = { start_utc: s.start_utc, technician_id: s.technician_id, bay_id: s.bay_id || null };
        box.querySelectorAll(".kn-slot").forEach(function (x) {
          x.classList.remove("on");
        });
        b.classList.add("on");
      });
      box.appendChild(b);
    });
  }

  // --- Étape 8 : RÉCAPITULATIF ----------------------------------------------
  function renderRecap(body) {
    body.appendChild(el('<h2 class="kn-h">Récapitulatif</h2>'));
    refreshCart().then(function () {
      clear(body);
      body.appendChild(el('<h2 class="kn-h">Récapitulatif</h2>'));
      var p = state.cart.pricing;
      var ticket = el('<div class="kn-ticket"></div>');
      state.cart.items.forEach(function (it) {
        ticket.appendChild(el('<div class="kn-line"><span>' + esc(it.label) + " ×" + it.quantity + "</span><span>" + euro(it.unit_price_cents * it.quantity) + "</span></div>"));
      });
      if (p.cumul_discount_cents > 0) ticket.appendChild(el('<div class="kn-line kn-ok"><span>Remise groupée</span><span>−' + euro(p.cumul_discount_cents) + "</span></div>"));
      ticket.appendChild(el('<div class="kn-line kn-total"><span>Total TVAC</span><span>' + p.total_tvac_formatted + "</span></div>"));
      body.appendChild(ticket);

      // Coupon.
      var cf = el('<div class="kn-field"><label>Code promo</label><input id="kn-coupon" value="' + esc(state.cart.coupon_code || "") + '"></div>');
      body.appendChild(cf);
      var applyC = el('<button class="kn-link" type="button">Appliquer le code</button>');
      applyC.addEventListener("click", function () {
        api("/cart/" + state.token + "/coupon", { method: "POST", body: { code: cf.querySelector("input").value } }).then(function (snap) {
          state.cart = snap;
          goto("recap");
        });
      });
      body.appendChild(applyC);

      body.appendChild(reassure("Annulation sans frais jusqu'à 24 h avant. Vous payez après l'intervention."));

      var actions = el('<div class="kn-actions"></div>');
      var confirm = el('<button class="kn-btn kn-btn-primary" type="button">Confirmer la demande</button>');
      confirm.addEventListener("click", submitBooking);
      actions.appendChild(confirm);
      body.appendChild(actions);
    });
  }
  function submitBooking() {
    // event_id partagé pixel/serveur pour la déduplication Meta CAPI.
    var eventId = state._eventId || (state._eventId = uuid());
    var payload = {
      token: state.token,
      customer: state.customer,
      address: state.address,
      slots: state.slots,
      answers: state.answers,
      consent_terms: !!state._consent,
      event_id: eventId,
      hp: "",
    };
    api("/bookings", { method: "POST", body: payload })
      .then(function (res) {
        state.booking = res;
        // GA4 purchase (dataLayer de la page hôte) + Meta pixel dédupliqué serveur.
        var p = state.cart && state.cart.pricing ? state.cart.pricing : null;
        dl("purchase", {
          transaction_id: res.reference,
          event_id: eventId,
          value: p ? p.total_tvac_cents / 100 : undefined,
          currency: "EUR",
          items: cartItemsForTracking(),
        });
        goto("done");
        reset(); // panier consommé
      })
      .catch(function (e) {
        if (e.status === 409) flash("Ce créneau vient d'être réservé. Merci d'en choisir un autre.");
        else flash(e.message || "Une erreur est survenue.");
      });
  }
  // --- Tracking GA4 (dataLayer) ---------------------------------------------
  function dl(event, data) {
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push(Object.assign({ event: event }, data || {}));
    } catch (e) {}
  }
  function cartItemsForTracking() {
    if (!state.cart || !state.cart.items) return [];
    return state.cart.items.map(function (it) {
      return { item_name: it.label, quantity: it.quantity, price: it.unit_price_cents / 100 };
    });
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return "xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  // --- Étape 9 : CONFIRMATION ------------------------------------------------
  function renderDone(body) {
    var ref = state.booking ? state.booking.reference : "";
    body.appendChild(el('<div class="kn-done"><div class="kn-done-mark">✓</div>' +
      '<h2 class="kn-h">C\'est confirmé</h2>' +
      '<p>Votre demande <strong>' + esc(ref) + '</strong> est bien enregistrée.</p>' +
      '<p class="kn-muted">Vous recevez un email de confirmation. Vous payez après l\'intervention.</p>' +
      '<p>Une question ? Appelez-nous au <a href="tel:+3240000000">+32 4 000 00 00</a>.</p></div>'));
    var again = el('<button class="kn-btn kn-btn-ghost" type="button">Réserver une autre prestation</button>');
    again.addEventListener("click", function () {
      state = load() || {};
      reset();
      window.location.reload();
    });
    body.appendChild(again);
  }

  // --- Composants réutilisables ---------------------------------------------
  function choiceCard(icon, title, desc, onClick, active, imageUrl) {
    // Média à gauche : image (vignette) si disponible, sinon emoji/icône.
    var media = imageUrl
      ? '<img class="kn-card-img" src="' + esc(imageUrl) + '" alt="" loading="lazy">'
      : icon
      ? '<span class="kn-card-icon" aria-hidden="true">' + icon + "</span>"
      : "";
    var c = el(
      '<button type="button" class="kn-card ' +
        (active ? "on" : "") +
        '">' +
        (media ? '<span class="kn-card-media">' + media + "</span>" : "") +
        '<span class="kn-card-body">' +
        '<span class="kn-card-title">' +
        esc(title) +
        "</span>" +
        (desc ? '<span class="kn-card-desc">' + esc(desc) + "</span>" : "") +
        "</span>" +
        "</button>"
    );
    c.addEventListener("click", onClick);
    return c;
  }
  function reassure(text) {
    return el('<p class="kn-reassure">' + esc(text) + "</p>");
  }
  // Message affiché quand l'adresse est hors zone de service : au lieu d'un
  // cul-de-sac, propose un contact réel (téléphone/email) pour un devis sur
  // mesure. Les coordonnées viennent de /api/catalog (catalog.contact).
  function outOfZoneMessage() {
    var contact = (catalog && catalog.contact) || {};
    var links = "";
    if (contact.phone) {
      links += '<a class="kn-link" href="tel:' + esc(contact.phone.replace(/\s+/g, "")) + '">' + esc(contact.phone) + "</a>";
    }
    if (contact.email) {
      links += (links ? " · " : "") + '<a class="kn-link" href="mailto:' + esc(contact.email) + '">' + esc(contact.email) + "</a>";
    }
    var wrap = el('<div class="kn-alert"></div>');
    wrap.appendChild(
      el('<p style="margin:0 0 8px;">Nous n\'intervenons pas encore automatiquement à cette adresse. Contactez-nous pour un devis sur mesure :</p>')
    );
    wrap.appendChild(el("<p style=\"margin:0;\">" + (links || "Contactez-nous depuis notre site.") + "</p>"));
    return wrap;
  }
  function backLink(onClick, label) {
    var b = el('<button type="button" class="kn-back">' + esc(label || "← Revenir") + "</button>");
    b.addEventListener("click", onClick);
    return b;
  }
  function loading() {
    return el('<p class="kn-muted">Chargement…</p>');
  }
  function clear(node) {
    node.innerHTML = "";
    return node;
  }
  function flash(msg) {
    var f = shadow.querySelector(".kn-flash");
    if (f) f.remove();
    var n = el('<div class="kn-flash">' + esc(msg) + "</div>");
    root.appendChild(n);
    setTimeout(function () {
      n.remove();
    }, 3500);
  }
  function quoteBar() {
    var p = state.cart.pricing;
    var total = p ? p.total_tvac_formatted : "";
    var bar = el(
      '<button type="button" class="kn-quotebar"><span>' +
        state.cart.item_count +
        " prestation" +
        (state.cart.item_count > 1 ? "s" : "") +
        '</span><span class="kn-quotebar-total">' +
        total +
        "</span></button>"
    );
    bar.addEventListener("click", function () {
      goto("cart");
    });
    return bar;
  }

  // --- Helpers d'état --------------------------------------------------------
  function ensureCart() {
    if (state.token) return Promise.resolve(state.token);
    return api("/cart", { method: "POST" }).then(function (d) {
      state.token = d.token;
      return d.token;
    });
  }
  function refreshCart() {
    if (!state.token) return Promise.resolve();
    return api("/cart/" + state.token)
      .then(function (snap) {
        state.cart = snap;
      })
      .catch(function () {});
  }
  function cartHasMode(mode) {
    return state.cart && state.cart.items && state.cart.items.some(function (it) {
      return it.mode === mode;
    });
  }

  // --- Démarrage -------------------------------------------------------------
  mountShell();
  replaySession();

  // --- CSS (tokens de marque inline, scopé au Shadow DOM) -------------------
  function CSS() {
    return (
      ".kn{--a:#586FF3;--ai:#3A4BC0;--blush:#F7D7E2;--ink:#141A2E;--muted:#5C6479;--paper:#FBFBFD;--surface:#fff;--line:#E4E6EF;--ok:#1D7A54;--alert:#B4322D;" +
      // padding-top tient compte de la zone système du téléphone (encoche,
      // heure, batterie…) : évite que la barre de progression soit masquée
      // en haut d'écran sur mobile, sans changer l'espacement ailleurs.
      "font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:16px;line-height:1.6;color:var(--ink);background:var(--paper);max-width:560px;margin:0 auto;padding:max(16px,env(safe-area-inset-top)) 16px 16px;box-sizing:border-box;display:flex;flex-direction:column}" +
      ".kn *{box-sizing:border-box}" +
      ".kn-h{font-size:1.5rem;margin:8px 0 16px}.kn-h3{font-size:1.05rem;margin:16px 0 8px}" +
      ".kn-muted{color:var(--muted);font-size:.875rem}" +
      ".kn-progress{margin-bottom:16px;flex:0 0 auto}" +
      ".kn-progress-track{height:8px;background:var(--line);border-radius:999px;overflow:hidden}" +
      ".kn-progress-fill{height:100%;background:var(--a);border-radius:999px;transition:width .35s ease}" +
      ".kn-progress-label{font-size:.78rem;color:var(--muted);font-weight:600;margin-top:6px}" +
      // Conteneur de défilement interne et autonome : un « Typeform en boîte »,
      // pas une prise de contrôle du viewport du navigateur (widget embarqué
      // en bloc normal dans une page hôte arbitraire, sans iframe).
      // scroll-snap-type "proximity" (pas "mandatory") : aimante seulement
      // quand on est déjà proche d'un point d'ancrage, laisse le défilement
      // libre sur un panneau plus grand que la boîte — "mandatory" empêchait
      // d'atteindre le bas d'une étape plus haute que le conteneur.
      ".kn-scroller{overflow-y:auto;scroll-snap-type:y proximity;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain;height:min(760px,90vh);position:relative}" +
      "@supports (height:100dvh){.kn-scroller{height:min(760px,90dvh)}}" +
      ".kn-step-panel{min-height:100%;scroll-snap-align:start;display:flex;flex-direction:column;justify-content:center;padding:8px 0;outline:none}" +
      ".kn-postal-wrap{display:flex;flex-direction:column;gap:8px;margin-top:4px}" +
      ".kn-postal-wrap[hidden]{display:none}" +
      ".kn-cards{display:grid;gap:12px;margin:12px 0}.kn-cards-sm{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}" +
      ".kn-card{display:flex;flex-direction:row;align-items:center;gap:14px;text-align:left;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:14px;min-height:48px;cursor:pointer;font:inherit;color:inherit}" +
      ".kn-card.on{border-color:var(--a);box-shadow:0 0 0 2px var(--a) inset}" +
      ".kn-card-media{flex:0 0 auto;display:flex;align-items:center;justify-content:center}" +
      ".kn-card-img{width:64px;height:64px;object-fit:cover;border-radius:10px;display:block}" +
      ".kn-card-body{display:flex;flex-direction:column;gap:2px;min-width:0}" +
      ".kn-card-icon{font-size:1.6rem;width:44px;text-align:center}.kn-card-title{font-weight:700}.kn-card-desc{color:var(--muted);font-size:.85rem}" +
      ".kn-extra-img{width:48px;height:48px;object-fit:cover;border-radius:8px;flex:0 0 auto}.kn-extra-txt{flex:1;min-width:0}" +
      ".kn-field{margin:12px 0}.kn-field label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:4px}" +
      ".kn input[type=text],.kn input[type=email],.kn input[type=tel],.kn input:not([type]){width:100%;min-height:48px;padding:0 12px;font-size:16px;font-family:inherit;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink)}" +
      ".kn input:focus{outline:2px solid var(--a);outline-offset:2px}" +
      ".kn-extras{display:flex;flex-direction:column;gap:8px}" +
      ".kn-extra{display:flex;justify-content:space-between;align-items:center;gap:12px;border:1px solid var(--line);border-radius:8px;padding:12px;min-height:48px;cursor:pointer}" +
      ".kn-extra input{width:22px;height:22px}" +
      ".kn-choice-row{display:flex;gap:8px;flex-wrap:wrap}" +
      ".kn-chip{min-height:48px;padding:0 20px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:inherit;font:inherit;cursor:pointer}" +
      ".kn-chip.on{border-color:var(--a);background:var(--a);color:#fff}" +
      ".kn-live{margin:16px 0;text-align:center}.kn-live-price{font-size:2rem;font-weight:700;font-variant-numeric:tabular-nums}" +
      ".kn-actions{display:flex;flex-direction:column;gap:8px;margin:16px 0}" +
      ".kn-btn{min-height:48px;padding:0 24px;border-radius:8px;font:inherit;font-weight:700;border:1px solid transparent;cursor:pointer}" +
      ".kn-btn-primary{background:var(--a);color:#fff}.kn-btn-primary:hover{background:var(--ai)}" +
      ".kn-btn-ghost{background:transparent;color:var(--ai);border-color:var(--line)}" +
      ".kn-link{background:none;border:0;color:var(--ai);text-decoration:underline;cursor:pointer;font:inherit;padding:4px 0}" +
      ".kn-back{background:none;border:0;color:var(--muted);cursor:pointer;font:inherit;margin-top:8px}" +
      ".kn-badge{display:inline-block;font-size:.72rem;background:var(--blush);color:var(--ai);border-radius:20px;padding:1px 8px}" +
      ".kn-reassure{background:#F1F4FF;border-left:3px solid var(--a);padding:8px 12px;border-radius:0 8px 8px 0;font-size:.875rem;color:var(--ai)}" +
      ".kn-alert{background:#FBEAE9;color:var(--alert);padding:12px;border-radius:8px}" +
      ".kn-ticket{font-variant-numeric:tabular-nums;border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--surface)}" +
      ".kn-line{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed var(--line)}" +
      ".kn-line-r{display:flex;flex-direction:column;align-items:flex-end;gap:4px}" +
      ".kn-total{border-bottom:0;border-top:2px solid var(--ink);font-weight:700;font-size:1.15rem;margin-top:4px}" +
      ".kn-ok{color:var(--ok)}" +
      ".kn-dates{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px}" +
      ".kn-date{white-space:nowrap;min-height:44px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);font:inherit;cursor:pointer}" +
      ".kn-date.on{border-color:var(--a);color:var(--a);font-weight:700}" +
      ".kn-slot-list{display:grid;gap:8px;margin-top:8px}" +
      ".kn-slot{min-height:48px;border:1px solid var(--line);border-radius:8px;background:var(--surface);font:inherit;cursor:pointer}" +
      ".kn-slot.on{border-color:var(--a);background:var(--a);color:#fff}" +
      // Barre panier : hors du scroller (frère normal-flow, pas de sticky à
      // l'intérieur d'un conteneur scroll-snap — comportement incohérent
      // inter-navigateurs sinon, la barre n'étant pas une cible de snap valide).
      ".kn-quotebar-slot{flex:0 0 auto;padding-bottom:env(safe-area-inset-bottom)}" +
      ".kn-quotebar{width:100%;display:flex;justify-content:space-between;align-items:center;min-height:56px;padding:0 16px;margin-top:16px;background:var(--ink);color:#fff;border:0;border-radius:12px;font:inherit;cursor:pointer}" +
      ".kn-quotebar-total{font-weight:700;font-size:1.15rem;font-variant-numeric:tabular-nums}" +
      ".kn-flash{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--ink);color:#fff;padding:12px 20px;border-radius:8px;z-index:9999}" +
      ".kn-done{text-align:center;padding:24px 0}.kn-done-mark{width:64px;height:64px;line-height:64px;border-radius:50%;background:var(--ok);color:#fff;font-size:2rem;margin:0 auto 16px}" +
      "@media(prefers-reduced-motion:reduce){.kn *{scroll-behavior:auto!important}}"
    );
  }
})();
