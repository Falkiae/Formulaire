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
  // Convertit un montant HT (catalogue) en affichage TVAC — tous les prix
  // côté client sont TVAC, la ventilation HT/TVA reste interne (facturation).
  function tvac(centsHt) {
    var bp = (catalog && catalog.vat_rate_bp) || 2100;
    return euro(Math.round((centsHt * (10000 + bp)) / 10000));
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

  // Textes fixes par défaut, éditables depuis /admin/formulaire/textes.
  // Filet de secours uniquement (si /api/catalog échoue totalement) — la
  // source de vérité normale est catalog.texts, fusionnée côté serveur sur
  // les mêmes valeurs par défaut (src/Support/TunnelTexts.php).
  var TEXT_DEFAULTS = {
    where: {
      title: "Où souhaitez-vous être nettoyé ?",
      onsite_title: "Je veux qu'on vienne chez moi",
      onsite_subtitle: "Un technicien se déplace à votre adresse.",
      workshop_title: "Je viens à l'atelier",
      workshop_subtitle: "Vous déposez, nous nettoyons. Souvent moins cher.",
      postal_label: "Votre code postal",
      postal_placeholder: "Ex. 4000",
      continue_button: "Continuer",
      zone_reassurance: "Oui, nous intervenons à Liège et dans un rayon de 25 km.",
      postal_required_error: "Indiquez d'abord votre code postal.",
      zone_check_error: "Impossible de vérifier votre zone pour le moment.",
    },
    what: {
      title: "Quelle prestation ?",
      back_link: "← Revenir",
    },
    details: {
      title: "Configurez votre prestation",
      variants_heading: "Votre modèle",
      extras_heading: "Options",
      price_reassurance: "Prix ferme. Aucun supplément le jour de l'intervention.",
      add_to_cart_button: "Ajouter au panier",
      price_duration_label: "TVAC · durée estimée",
      unavailable_mode_error: "Indisponible dans ce mode.",
      add_to_cart_error: "Impossible d'ajouter cette prestation au panier.",
    },
    cart: {
      title: "Votre devis",
      empty_message: "Votre panier est vide. Ajoutez une prestation pour commencer.",
      empty_button: "Choisir une prestation",
      onsite_badge: "À domicile",
      workshop_badge: "Atelier",
      discount_label: "Remise groupée",
      total_label: "Total TVAC",
      add_another_button: "Ajouter une autre prestation",
      checkout_button: "Finaliser ma réservation",
    },
    intake: {
      title: "Quelques précisions",
      reassurance: "Si l'état diffère, on vous prévient avant de commencer. Vous restez libre de refuser.",
      continue_button: "Continuer",
      validation_error: "Merci de compléter les champs requis.",
    },
    contact: {
      title: "Vos coordonnées",
      first_name_label: "Prénom",
      last_name_label: "Nom",
      email_label: "Email",
      phone_label: "Téléphone",
      address_heading: "Adresse d'intervention",
      street_label: "Rue",
      number_label: "Numéro",
      postal_label: "Code postal",
      city_label: "Ville",
      privacy_reassurance: "Vos données servent uniquement à organiser votre rendez-vous. Conservées le temps légal. Voir notre politique de confidentialité.",
      consent_label: "J'accepte les conditions générales et la politique de confidentialité.",
      continue_button: "Choisir un créneau",
      required_error: "Merci d'indiquer au moins votre prénom et votre email.",
      consent_error: "Merci d'accepter les conditions pour continuer.",
    },
    slot: {
      title: "Choisissez votre créneau",
      empty_onsite: "Aucun créneau à domicile disponible sur la période.",
      empty_workshop: "Aucun créneau atelier disponible sur la période.",
      onsite_heading: "À domicile",
      workshop_heading: "À l'atelier",
      sms_reassurance: "Vous recevez un SMS quand le technicien part vers chez vous.",
      continue_button: "Continuer",
      select_required_error: "Merci de choisir un créneau.",
      load_error: "Impossible de vérifier les disponibilités pour le moment.",
      retry_button: "Réessayer",
      morning_label: "Matin",
      afternoon_label: "Après-midi",
      evening_label: "Soir",
      workshop_slot_prefix: "Dépôt ",
      workshop_slot_reprise: " · reprise ~",
    },
    recap: {
      title: "Récapitulatif",
      discount_label: "Remise groupée",
      total_label: "Total TVAC",
      promo_label: "Code promo",
      promo_button: "Appliquer le code",
      cancellation_reassurance: "Annulation sans frais jusqu'à 24 h avant. Vous payez après l'intervention.",
      confirm_button: "Confirmer la demande",
      slot_taken_error: "Ce créneau vient d'être réservé. Merci d'en choisir un autre.",
    },
    done: {
      title: "C'est confirmé",
      message_prefix: "Votre demande ",
      message_suffix: " est bien enregistrée.",
      subtext: "Vous recevez un email de confirmation. Vous payez après l'intervention.",
      contact_prefix: "Une question ? Appelez-nous au ",
      contact_fallback_phone: "+32 4 000 00 00",
      restart_button: "Réserver une autre prestation",
    },
    shared: {
      loading: "Chargement…",
      generic_error: "Une erreur est survenue.",
      out_of_zone_message: "Nous n'intervenons pas encore automatiquement à cette adresse. Contactez-nous pour un devis sur mesure :",
      out_of_zone_fallback: "Contactez-nous depuis notre site.",
    },
  };
  // Résout "étape.clé" depuis catalog.texts (édité en admin), avec repli sur
  // TEXT_DEFAULTS si catalog n'est pas encore chargé ou si la clé est absente.
  function t(path) {
    var parts = path.split(".");
    var custom = (catalog && catalog.texts) || {};
    var def = TEXT_DEFAULTS;
    var val = custom[parts[0]] && custom[parts[0]][parts[1]];
    if (val) return val;
    def = def[parts[0]] && def[parts[0]][parts[1]];
    return def || "";
  }

  // Éléments persistants du « chrome » (montés une fois par mountShell()).
  var scroller, progressFillEl, progressLabelEl, quoteBarSlot, stepObserver;

  // --- Montage du chrome (barre de progression + scroller + panier) ---------
  function mountShell() {
    var progress = el('<div class="kn-progress" role="navigation" aria-label="Étapes"></div>');
    var progressInner = el('<div class="kn-progress-inner"></div>');
    var track = el('<div class="kn-progress-track"></div>');
    progressFillEl = el('<div class="kn-progress-fill"></div>');
    track.appendChild(progressFillEl);
    progressInner.appendChild(track);
    progressLabelEl = el('<div class="kn-progress-label"></div>');
    progressInner.appendChild(progressLabelEl);
    progress.appendChild(progressInner);
    root.appendChild(progress);

    scroller = el('<div class="kn-scroller"></div>');
    root.appendChild(scroller);

    quoteBarSlot = el('<div class="kn-quotebar-slot"></div>');
    root.appendChild(quoteBarSlot);

    setupStepObserver();
    setupViewportGuard();
  }

  // Sous Safari iOS, la barre d'outils apparaît/disparaît dynamiquement au
  // défilement sans que le viewport de layout (sur lequel se calent les
  // éléments position:fixed) ne change — le viewport VISUEL, lui, change.
  // Résultat : un bandeau fixé en bas peut se retrouver masqué sous la barre
  // d'outils. window.visualViewport expose l'écart réel ; on l'utilise pour
  // décaler la barre de progression et le bandeau panier au plus près du
  // viewport réellement visible. Navigateurs sans visualViewport : pas de
  // correction, comportement CSS de base inchangé.
  function setupViewportGuard() {
    if (!("visualViewport" in window)) return;
    var vv = window.visualViewport;
    var raf = null;
    function apply() {
      raf = null;
      var bottomGap = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
      var topGap = Math.max(0, vv.offsetTop);
      root.style.setProperty("--kn-vv-bottom", bottomGap + "px");
      root.style.setProperty("--kn-vv-top", topGap + "px");
    }
    function schedule() {
      if (raf === null) raf = requestAnimationFrame(apply);
    }
    vv.addEventListener("resize", schedule);
    vv.addEventListener("scroll", schedule);
    apply();
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
          updateQuoteBar();
          // Persistance différée (pas à chaque callback pendant un flick rapide).
          clearTimeout(saveTimer);
          saveTimer = setTimeout(save, 400);
        }
      },
      { root: null, threshold: [0.5] }
    );
  }

  function stepIndex(key) {
    return STEPS.findIndex(function (s) {
      return s.key === key;
    });
  }

  function updateProgressBar(step) {
    var idx = stepIndex(step);
    var safeIdx = idx < 0 ? 0 : idx;
    var pct = Math.round(((safeIdx + 1) / STEPS.length) * 100);
    progressFillEl.style.width = pct + "%";
    progressLabelEl.textContent = "Étape " + (safeIdx + 1) + "/" + STEPS.length + " · " + STEPS[safeIdx].label;
  }

  // Masqué à partir de l'étape Panier (incluse) : une fois le devis établi,
  // le prospect entre dans le tunnel de finalisation et ne doit plus être
  // distrait par le bouton flottant (qui fait par ailleurs doublon avec le
  // ticket affiché en pleine page dès l'étape Panier elle-même).
  function updateQuoteBar() {
    quoteBarSlot.innerHTML = "";
    var show = !!(state.cart && state.cart.item_count > 0 && stepIndex(state.step) >= 0 && stepIndex(state.step) < stepIndex("cart"));
    root.classList.toggle("has-quotebar", show);
    if (show) {
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
    if ("onscrollend" in window) {
      window.addEventListener("scrollend", doFocus, { once: true });
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
    body.appendChild(el('<h2 class="kn-h">' + esc(t("where.title")) + "</h2>"));

    var cards = el('<div class="kn-cards"></div>');
    var onsiteCard = choiceCard("🏠", t("where.onsite_title"), t("where.onsite_subtitle"), function () {
      // Retour visuel immédiat : state.mode n'est posé qu'au clic sur
      // Continuer, mais la sélection doit déjà être visible pendant la
      // saisie du code postal.
      onsiteCard.classList.add("on");
      workshopCard.classList.remove("on");
      showPostal();
    }, state.mode === "onsite");
    var workshopCard = choiceCard("🔧", t("where.workshop_title"), t("where.workshop_subtitle"), function () {
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
      '<div class="kn-field"><label for="kn-postal">' + esc(t("where.postal_label")) + "</label>" +
        '<input id="kn-postal" inputmode="numeric" autocomplete="postal-code" maxlength="4" value="' +
        esc(state.postal) +
        '" placeholder="' + esc(t("where.postal_placeholder")) + '"></div>'
    );
    postalWrap.appendChild(field);
    var postalMsgWrap = el("<div></div>");
    postalWrap.appendChild(postalMsgWrap);
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("where.continue_button")) + "</button>");
    cont.addEventListener("click", function () {
      pickMode("onsite");
    });
    postalWrap.appendChild(cont);
    body.appendChild(postalWrap);

    var input = field.querySelector("input");
    // Vérification de zone dès la saisie (débounced), pour un retour immédiat
    // sans attendre le clic sur Continuer — pickMode() reste l'unique point de
    // blocage autoritaire (voir plus bas), ceci n'est qu'un retour visuel.
    var checkTimer = null;
    input.addEventListener("input", function () {
      state.postal = input.value.replace(/\D/g, "").slice(0, 4);
      postalMsgWrap.innerHTML = "";
      clearTimeout(checkTimer);
      if (state.postal.length === 4) {
        checkTimer = setTimeout(function () {
          checkPostalZone(state.postal).then(function (ok) {
            if (!ok && state.postal.length === 4) {
              postalMsgWrap.innerHTML = "";
              postalMsgWrap.appendChild(outOfZoneMessage());
            }
          });
        }, 400);
      }
    });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") pickMode("onsite");
    });

    function showPostal() {
      postalWrap.hidden = false;
      input.focus();
    }
    if (state.mode === "onsite") showPostal();

    body.appendChild(reassure(t("where.zone_reassurance")));

    // Vérification autoritaire à la validation (toujours réévaluée, jamais de
    // cache pouvant être obsolète) : bloque le passage à l'étape suivante tant
    // que le code postal n'est pas couvert.
    function pickMode(mode) {
      if (mode === "onsite" && (!state.postal || state.postal.length < 4)) {
        flash(t("where.postal_required_error"));
        return;
      }
      if (mode !== "onsite") {
        proceedMode(mode);
        return;
      }
      checkPostalZone(state.postal).then(function (ok) {
        if (!ok) {
          postalMsgWrap.innerHTML = "";
          postalMsgWrap.appendChild(outOfZoneMessage());
          return;
        }
        // Synchronise dès la validation de zone, avant même l'étape
        // Coordonnées : state.postal reste l'unique source de vérité, une
        // correction faite ici (même après un premier passage par
        // Coordonnées avec un code invalide) ne doit jamais rester figée.
        state.address.postal_code = state.postal;
        proceedMode("onsite");
      });
    }
  }
  function proceedMode(mode) {
    state.mode = mode;
    ensureCart().then(function () {
      setTimeout(function () {
        goto("what");
      }, 300);
    });
  }
  // Panne réseau : ne bloque pas abusivement une saisie potentiellement
  // valide, cohérent avec le choix déjà fait ailleurs dans le tunnel.
  function checkPostalZone(postal) {
    return api("/zones/check?postal=" + encodeURIComponent(postal))
      .then(function (r) {
        return !!r.ok;
      })
      .catch(function () {
        flash(t("where.zone_check_error"));
        return true;
      });
  }

  // --- Étape 2 : QUOI (catégorie → service) ---------------------------------
  function renderWhat(body) {
    body.appendChild(el('<h2 class="kn-h">' + esc(t("what.title")) + "</h2>"));
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
              }, false, imgUrl(svc.image_path), svc.badge_label)
            );
          });
      });
      body.appendChild(backLink(function () {
        state.categoryId = null;
        renderStepInto(clear(body), state.step);
      }, t("what.back_link")));
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
    body.appendChild(el('<h2 class="kn-h">' + esc(t("details.title")) + "</h2>"));
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
    // Pré-sélection : tant que l'utilisateur n'a jamais choisi lui-même (state.variantId
    // remis à null au choix du service), on retient la variante marquée par défaut
    // côté admin, sinon la première — jamais aucune sélection par défaut.
    if (cfg.variants.length && !state.variantId) {
      var defaultVariant = cfg.variants.filter(function (v) {
        return v.is_default;
      })[0] || cfg.variants[0];
      state.variantId = defaultVariant.id;
    }
    if (cfg.variants.length) {
      body.appendChild(el('<h3 class="kn-h3">' + esc(t("details.variants_heading")) + "</h3>"));
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
      body.appendChild(el('<h3 class="kn-h3">' + esc(t("details.extras_heading")) + "</h3>"));
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
            tvac(x.price_cents) +
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

    body.appendChild(reassure(t("details.price_reassurance")));

    var live = el('<div class="kn-live"></div>');
    body.appendChild(live);
    livePrice(live);

    var actions = el('<div class="kn-actions"></div>');
    var add = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("details.add_to_cart_button")) + "</button>");
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
          '</div><div class="kn-muted">' + esc(t("details.price_duration_label")) + " " +
          q.total_active_duration_min +
          " min</div>";
      })
      .catch(function (err) {
        liveEl.innerHTML =
          '<p class="kn-muted">' +
          esc((err && err.data && err.data.error) || t("details.unavailable_mode_error")) +
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
          flash((err && err.data && err.data.error) || t("details.add_to_cart_error"));
        });
    });
  }

  // --- Étape 4 : PANIER ------------------------------------------------------
  function renderCart(body) {
    body.appendChild(el('<h2 class="kn-h">' + esc(t("cart.title")) + "</h2>"));
    refreshCart().then(function () {
      clear(body);
      body.appendChild(el('<h2 class="kn-h">' + esc(t("cart.title")) + "</h2>"));
      if (!state.cart || state.cart.item_count === 0) {
        body.appendChild(el('<p class="kn-muted">' + esc(t("cart.empty_message")) + "</p>"));
        var add0 = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("cart.empty_button")) + "</button>");
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
            esc(it.mode === "onsite" ? t("cart.onsite_badge") : t("cart.workshop_badge")) +
            "</span><br><span class=\"kn-muted\">×" +
            it.quantity +
            " · " +
            it.unit_duration_min +
            " min</span></div>" +
            '<div class="kn-line-r"><span>' +
            tvac(it.unit_price_cents * it.quantity) +
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
          ticket.appendChild(el('<div class="kn-line kn-ok"><span>' + esc(t("cart.discount_label")) + "</span><span>−" + tvac(p.cumul_discount_cents) + "</span></div>"));
        }
        ticket.appendChild(el('<div class="kn-line kn-total"><span>' + esc(t("cart.total_label")) + "</span><span>" + p.total_tvac_formatted + "</span></div>"));
      }
      body.appendChild(ticket);

      var actions = el('<div class="kn-actions"></div>');
      var more = el('<button class="kn-btn kn-btn-ghost" type="button">' + esc(t("cart.add_another_button")) + "</button>");
      more.addEventListener("click", function () {
        goto("what");
      });
      var cont = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("cart.checkout_button")) + "</button>");
      cont.addEventListener("click", function () {
        var p = state.cart && state.cart.pricing ? state.cart.pricing : null;
        dl("begin_checkout", { currency: "EUR", value: p ? p.total_tvac_cents / 100 : undefined, items: cartItemsForTracking() });
        // Toujours refetch (pas de cache) : la composition du panier a pu
        // changer depuis le dernier appel, la pertinence des questions doit
        // être recalculée à chaque clic sur ce bouton.
        api("/form?token=" + encodeURIComponent(state.token))
          .then(function (f) {
            state._form = f;
          })
          .catch(function () {
            state._form = { fields: [], conditions: [] };
          })
          .then(function () {
            goto(state._form.fields.length === 0 ? "contact" : "intake");
          });
      });
      actions.appendChild(more);
      actions.appendChild(cont);
      body.appendChild(actions);
    });
  }

  // --- Étape 5 : QUESTIONS D'INTAKE (formulaire dynamique) ------------------
  function renderIntake(body) {
    body.appendChild(el('<h2 class="kn-h">' + esc(t("intake.title")) + "</h2>"));
    if (!state._form) {
      body.appendChild(loading());
      api("/form?token=" + encodeURIComponent(state.token))
        .then(function (f) {
          state._form = f;
          if (f.fields.length === 0) {
            // Reprise de session sur une étape devenue vide entretemps (aucun
            // risque de réentrance ici : ce callback s'exécute toujours après
            // le retour du goto() qui a mené à ce panneau).
            goto("contact");
            return;
          }
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

    body.appendChild(reassure(t("intake.reassurance")));
    var actions = el('<div class="kn-actions"></div>');
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("intake.continue_button")) + "</button>");
    cont.addEventListener("click", function () {
      if (!validateForm(hasOnsite)) {
        flash(t("intake.validation_error"));
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
    body.appendChild(el('<h2 class="kn-h">' + esc(t("contact.title")) + "</h2>"));
    var hasOnsite = cartHasMode("onsite");
    var fields = [
      ["first_name", t("contact.first_name_label"), "given-name", "text"],
      ["last_name", t("contact.last_name_label"), "family-name", "text"],
      ["email", t("contact.email_label"), "email", "email"],
      ["phone", t("contact.phone_label"), "tel", "tel"],
    ];
    fields.forEach(function (f) {
      body.appendChild(textField("customer", f[0], f[1], f[2], f[3]));
    });
    if (hasOnsite) {
      body.appendChild(el('<h3 class="kn-h3">' + esc(t("contact.address_heading")) + "</h3>"));
      body.appendChild(textField("address", "street", t("contact.street_label"), "address-line1", "text"));
      body.appendChild(textField("address", "number", t("contact.number_label"), "", "text"));
      // Toujours resynchronisé depuis state.postal (validé à l'étape « Où »),
      // jamais figé à la première visite : un retour en arrière pour corriger
      // le code postal ne doit jamais laisser une ancienne valeur invalide
      // traîner jusqu'à l'étape créneau (state.address.postal_code y est lu
      // en priorité, voir renderSlot()). L'utilisateur reste libre de la
      // modifier manuellement ensuite dans ce champ.
      state.address.postal_code = state.postal;
      body.appendChild(textField("address", "postal_code", t("contact.postal_label"), "postal-code", "text"));
      body.appendChild(textField("address", "city", t("contact.city_label"), "address-level2", "text"));
    }
    body.appendChild(reassure(t("contact.privacy_reassurance")));
    var consent = el('<label class="kn-extra"><span>' + esc(t("contact.consent_label")) + '</span><input type="checkbox" id="kn-consent"></label>');
    body.appendChild(consent);
    var actions = el('<div class="kn-actions"></div>');
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("contact.continue_button")) + "</button>");
    cont.addEventListener("click", function () {
      if (!state.customer.email || !state.customer.first_name) {
        flash(t("contact.required_error"));
        return;
      }
      if (!consent.querySelector("input").checked) {
        flash(t("contact.consent_error"));
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
    body.appendChild(el('<h2 class="kn-h">' + esc(t("slot.title")) + "</h2>"));
    body.appendChild(loading());
    api("/availability", {
      method: "POST",
      body: { token: state.token, postal: state.address.postal_code || state.postal, days: 10 },
    }).then(function (av) {
      state.availability = av;
      clear(body);
      body.appendChild(el('<h2 class="kn-h">' + esc(t("slot.title")) + "</h2>"));

      ["onsite", "workshop"].forEach(function (mode) {
        var block = av[mode];
        if (!block) return;
        if (block.status === "out_of_zone") {
          body.appendChild(outOfZoneMessage());
          return;
        }
        if (block.status !== "ok" || !block.slots || !block.slots.length) {
          body.appendChild(el('<p class="kn-muted">' + esc(mode === "onsite" ? t("slot.empty_onsite") : t("slot.empty_workshop")) + "</p>"));
          return;
        }
        body.appendChild(el('<h3 class="kn-h3">' + esc(mode === "onsite" ? t("slot.onsite_heading") : t("slot.workshop_heading")) + "</h3>"));
        body.appendChild(slotPicker(mode, block.slots));
      });

      body.appendChild(reassure(t("slot.sms_reassurance")));
      var actions = el('<div class="kn-actions"></div>');
      var cont = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("slot.continue_button")) + "</button>");
      cont.addEventListener("click", function () {
        var need = [];
        if (av.onsite && av.onsite.status === "ok") need.push("onsite");
        if (av.workshop && av.workshop.status === "ok") need.push("workshop");
        var ok = need.every(function (m) {
          return state.slots[m];
        });
        if (!ok) {
          flash(t("slot.select_required_error"));
          return;
        }
        goto("recap");
      });
      actions.appendChild(cont);
      body.appendChild(actions);
    })
    .catch(function (err) {
      clear(body);
      body.appendChild(el('<h2 class="kn-h">' + esc(t("slot.title")) + "</h2>"));
      body.appendChild(el('<p class="kn-alert">' + esc(t("slot.load_error")) + "</p>"));
      var retry = el('<button class="kn-btn kn-btn-ghost" type="button">' + esc(t("slot.retry_button")) + "</button>");
      retry.addEventListener("click", function () {
        goto("slot");
      });
      body.appendChild(retry);
      flash(err && err.data && err.data.error ? err.data.error : t("shared.generic_error"));
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
  // Le moteur de disponibilité renvoie un créneau DISTINCT par technicien
  // libre à une même heure (technician_id porté par chaque créneau, pour que
  // le serveur choisisse qui l'exécute) — le client ne choisit jamais son
  // technicien explicitement, donc on regroupe par heure pour n'afficher
  // qu'un seul bouton, quel que soit le nombre de techniciens libres.
  function groupSlotsByTime(slots) {
    var order = [];
    var groups = {};
    slots.forEach(function (s) {
      if (!groups[s.start_utc]) {
        groups[s.start_utc] = [];
        order.push(s.start_utc);
      }
      groups[s.start_utc].push(s);
    });
    return order.map(function (startUtc) {
      return groups[startUtc];
    });
  }
  // Matin/après-midi/soir : rend une longue liste de créneaux scannable en un
  // coup d'œil plutôt qu'un mur de boutons identiques (utile dès qu'une
  // journée a beaucoup de créneaux libres, ex. plusieurs techniciens).
  function timeOfDay(hhmm) {
    var h = parseInt(hhmm.split(":")[0], 10);
    if (h < 12) return "morning";
    if (h < 18) return "afternoon";
    return "evening";
  }
  function fillSlots(box, mode, slots) {
    box.innerHTML = "";
    var buckets = { morning: [], afternoon: [], evening: [] };
    groupSlotsByTime(slots).forEach(function (group) {
      buckets[timeOfDay(group[0].start_local.split(" ")[1])].push(group);
    });

    [
      ["morning", t("slot.morning_label")],
      ["afternoon", t("slot.afternoon_label")],
      ["evening", t("slot.evening_label")],
    ].forEach(function (pair) {
      var groups = buckets[pair[0]];
      if (!groups.length) return;
      box.appendChild(el('<div class="kn-slot-heading">' + esc(pair[1]) + "</div>"));
      groups.forEach(function (group) {
        var s = group[0];
        var label = mode === "onsite" ? s.start_local.split(" ")[1] : t("slot.workshop_slot_prefix") + s.start_local.split(" ")[1] + t("slot.workshop_slot_reprise") + s.pickup_local;
        var chosen = state.slots[mode] && state.slots[mode].start_utc === s.start_utc;
        var b = el('<button type="button" class="kn-slot ' + (chosen ? "on" : "") + '">' + esc(label) + "</button>");
        b.addEventListener("click", function () {
          // Garde le technicien déjà choisi s'il est toujours libre à ce
          // créneau, sinon prend le premier disponible du groupe.
          var current = state.slots[mode];
          var pick = (current && group.find(function (g) { return g.technician_id === current.technician_id; })) || group[0];
          state.slots[mode] = { start_utc: pick.start_utc, technician_id: pick.technician_id, bay_id: pick.bay_id || null };
          box.querySelectorAll(".kn-slot").forEach(function (x) {
            x.classList.remove("on");
          });
          b.classList.add("on");
        });
        box.appendChild(b);
      });
    });
  }

  // --- Étape 8 : RÉCAPITULATIF ----------------------------------------------
  function renderRecap(body) {
    body.appendChild(el('<h2 class="kn-h">' + esc(t("recap.title")) + "</h2>"));
    refreshCart().then(function () {
      clear(body);
      body.appendChild(el('<h2 class="kn-h">' + esc(t("recap.title")) + "</h2>"));
      var p = state.cart.pricing;
      var ticket = el('<div class="kn-ticket"></div>');
      state.cart.items.forEach(function (it) {
        ticket.appendChild(el('<div class="kn-line"><span>' + esc(it.label) + " ×" + it.quantity + "</span><span>" + tvac(it.unit_price_cents * it.quantity) + "</span></div>"));
      });
      if (p.cumul_discount_cents > 0) ticket.appendChild(el('<div class="kn-line kn-ok"><span>' + esc(t("recap.discount_label")) + "</span><span>−" + tvac(p.cumul_discount_cents) + "</span></div>"));
      ticket.appendChild(el('<div class="kn-line kn-total"><span>' + esc(t("recap.total_label")) + "</span><span>" + p.total_tvac_formatted + "</span></div>"));
      body.appendChild(ticket);

      // Coupon.
      var cf = el('<div class="kn-field"><label>' + esc(t("recap.promo_label")) + '</label><input id="kn-coupon" value="' + esc(state.cart.coupon_code || "") + '"></div>');
      body.appendChild(cf);
      var applyC = el('<button class="kn-link" type="button">' + esc(t("recap.promo_button")) + "</button>");
      applyC.addEventListener("click", function () {
        api("/cart/" + state.token + "/coupon", { method: "POST", body: { code: cf.querySelector("input").value } }).then(function (snap) {
          state.cart = snap;
          goto("recap");
        });
      });
      body.appendChild(applyC);

      body.appendChild(reassure(t("recap.cancellation_reassurance")));

      var actions = el('<div class="kn-actions"></div>');
      var confirm = el('<button class="kn-btn kn-btn-primary" type="button">' + esc(t("recap.confirm_button")) + "</button>");
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
        if (e.status === 409) flash(t("recap.slot_taken_error"));
        else flash(e.message || t("shared.generic_error"));
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
    var contact = (catalog && catalog.contact) || {};
    var phone = contact.phone || t("done.contact_fallback_phone");
    var phoneHref = "tel:" + phone.replace(/\s+/g, "");
    body.appendChild(el('<div class="kn-done"><div class="kn-done-mark">✓</div>' +
      '<h2 class="kn-h">' + esc(t("done.title")) + "</h2>" +
      '<p>' + esc(t("done.message_prefix")) + '<strong>' + esc(ref) + '</strong>' + esc(t("done.message_suffix")) + '</p>' +
      '<p class="kn-muted">' + esc(t("done.subtext")) + '</p>' +
      '<p>' + esc(t("done.contact_prefix")) + '<a href="' + esc(phoneHref) + '">' + esc(phone) + '</a>.</p></div>'));
    var again = el('<button class="kn-btn kn-btn-ghost" type="button">' + esc(t("done.restart_button")) + "</button>");
    again.addEventListener("click", function () {
      state = load() || {};
      reset();
      window.location.reload();
    });
    body.appendChild(again);
  }

  // --- Composants réutilisables ---------------------------------------------
  function choiceCard(icon, title, desc, onClick, active, imageUrl, badge) {
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
        (badge ? ' <span class="kn-badge">' + esc(badge) + "</span>" : "") +
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
      el('<p style="margin:0 0 8px;">' + esc(t("shared.out_of_zone_message")) + "</p>")
    );
    wrap.appendChild(el("<p style=\"margin:0;\">" + (links || esc(t("shared.out_of_zone_fallback"))) + "</p>"));
    return wrap;
  }
  function backLink(onClick, label) {
    var b = el('<button type="button" class="kn-back">' + esc(label || "← Revenir") + "</button>");
    b.addEventListener("click", onClick);
    return b;
  }
  function loading() {
    return el('<p class="kn-muted">' + esc(t("shared.loading")) + "</p>");
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
  // Préchargement en arrière-plan (non bloquant) : renderWhere() a besoin de
  // catalog.contact pour outOfZoneMessage() dès l'étape 1 ; renderWhat()
  // garde son propre filet de sécurité si l'utilisateur va plus vite que ce
  // chargement.
  if (!catalog) {
    api("/catalog").then(function (d) {
      catalog = d;
      // Le tout premier rendu (fait avant même le départ de cet appel, pour
      // un affichage instantané) a forcément utilisé les textes par défaut
      // faute de catalog.texts — on rafraîchit l'étape actuellement affichée
      // dès que possible, si son panneau existe toujours.
      var panel = findPanel(state.step);
      if (panel) renderStepInto(clear(panel), state.step);
    });
  }

  // --- CSS (tokens de marque inline, scopé au Shadow DOM) -------------------
  function CSS() {
    return (
      // Plein écran, mais avec défilement NATIF de la page (pas de conteneur
      // interne overflow:auto) : c'est le seul modèle sur lequel Safari iOS
      // rétracte fiablement sa barre d'adresse/outils (le rétractement est
      // câblé au défilement du document, pas à celui d'un enfant en scroll
      // propre) — un conteneur interne empêchait ce rétractement, ce qui
      // provoquait un chrome Safari toujours présent, mal comptabilisé par
      // 100dvh, avec un bouton panier parfois masqué par la barre d'URL.
      // La barre de progression et le bouton panier sont donc en
      // position:fixed, ancrés au vrai viewport, indépendants de tout calcul
      // de hauteur — .kn lui-même n'est plus qu'un conteneur en flux normal.
      "@import url('https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@0,6..96,400..700;1,6..96,400..700&family=Jost:ital,wght@0,300..700;1,400&display=swap');" +
      ".kn{--a:#4A63E7;--ai:#3B50C9;--blush:#EFC6CB;--royal-soft:#E5E9FB;--ink:#1B2A4A;--muted:#6E7891;--paper:#F7F4EC;--surface:#fff;--line:#E4DECF;--ok:#3E7C4F;--alert:#B3453E;" +
      "font-family:'Jost','Century Gothic',Futura,system-ui,sans-serif;font-size:16px;line-height:1.55;color:var(--ink);background:var(--paper);" +
      "display:block;width:100%;min-height:100vh;margin:0;position:relative}" +
      "@supports (height:100dvh){.kn{min-height:100dvh}}" +
      ".kn *{box-sizing:border-box}" +
      ".kn-h{font-family:'Bodoni Moda',Didot,serif;font-weight:600;font-size:1.6rem;margin:8px 0 16px}.kn-h3{font-family:'Bodoni Moda',Didot,serif;font-weight:600;font-size:1.15rem;margin:16px 0 8px}" +
      ".kn-muted{color:var(--muted);font-size:.875rem}" +
      // Barre de progression : fixe en haut du vrai viewport (jamais dans le
      // flux du défilement de page), fond opaque pour ne rien laisser passer
      // dessous, padding sensible à l'encoche/notch (viewport-fit=cover côté
      // page hôte). --kn-vv-top (posé par setupViewportGuard(), via
      // window.visualViewport) corrige un éventuel écart entre viewport de
      // layout et viewport visuel sous Safari iOS ; 0px par défaut ailleurs.
      ".kn-progress{position:fixed;top:var(--kn-vv-top,0px);left:0;right:0;z-index:20;background:var(--paper);border-bottom:1px solid var(--line);" +
      "padding:max(12px,env(safe-area-inset-top)) 16px 12px}" +
      ".kn-progress-inner{margin:0 auto;width:100%;max-width:560px}" +
      ".kn-progress-track{height:8px;background:var(--line);border-radius:999px;overflow:hidden}" +
      ".kn-progress-fill{height:100%;background:var(--a);border-radius:999px;transition:width .35s ease}" +
      ".kn-progress-label{font-size:.78rem;color:var(--muted);font-weight:600;margin-top:6px}" +
      // Le "scroller" n'est plus un conteneur de défilement : simple bloc en
      // flux normal, la page défile nativement. padding-top réserve l'espace
      // sous la barre de progression fixe ; padding-bottom réserve l'espace
      // au-dessus du bouton panier fixe uniquement quand il est affiché
      // (classe .has-quotebar posée/retirée par updateQuoteBar()).
      ".kn-scroller{display:block;padding:96px 16px 24px}" +
      ".kn.has-quotebar .kn-scroller{padding-bottom:96px}" +
      ".kn-step-panel{min-height:calc(100vh - 96px);display:flex;flex-direction:column;justify-content:center;padding:8px 0;margin:0 auto;width:100%;max-width:560px;outline:none}" +
      "@supports (height:100dvh){.kn-step-panel{min-height:calc(100dvh - 96px)}}" +
      ".kn-postal-wrap{display:flex;flex-direction:column;gap:8px;margin-top:4px}" +
      ".kn-postal-wrap[hidden]{display:none}" +
      ".kn-cards{display:grid;gap:12px;margin:12px 0}.kn-cards-sm{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}" +
      ".kn-card{display:flex;flex-direction:row;align-items:center;gap:14px;text-align:left;background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:14px;min-height:48px;cursor:pointer;font:inherit;color:inherit}" +
      ".kn-card.on{border-color:var(--a);box-shadow:0 0 0 2px var(--a) inset}" +
      ".kn-card-media{flex:0 0 auto;display:flex;align-items:center;justify-content:center}" +
      ".kn-card-img{width:64px;height:64px;object-fit:cover;border-radius:4px;display:block}" +
      ".kn-card-body{display:flex;flex-direction:column;gap:2px;min-width:0}" +
      ".kn-card-icon{font-size:1.6rem;width:44px;text-align:center}.kn-card-title{font-weight:700}.kn-card-desc{color:var(--muted);font-size:.85rem}" +
      ".kn-extra-img{width:48px;height:48px;object-fit:cover;border-radius:4px;flex:0 0 auto}.kn-extra-txt{flex:1;min-width:0}" +
      ".kn-field{margin:12px 0}.kn-field label{display:block;font-size:.85rem;color:var(--muted);margin-bottom:4px}" +
      ".kn input[type=text],.kn input[type=email],.kn input[type=tel],.kn input:not([type]){width:100%;min-height:48px;padding:0 12px;font-size:16px;font-family:inherit;border:1px solid var(--line);border-radius:4px;background:var(--surface);color:var(--ink)}" +
      ".kn input:focus{outline:2px solid var(--a);outline-offset:2px}" +
      ".kn-extras{display:flex;flex-direction:column;gap:8px}" +
      ".kn-extra{display:flex;justify-content:space-between;align-items:center;gap:12px;border:1px solid var(--line);border-radius:4px;padding:12px;min-height:48px;cursor:pointer}" +
      ".kn-extra input{width:22px;height:22px}" +
      ".kn-choice-row{display:flex;gap:8px;flex-wrap:wrap}" +
      ".kn-chip{min-height:48px;padding:0 20px;border:1px solid var(--line);border-radius:4px;background:var(--surface);color:inherit;font:inherit;cursor:pointer}" +
      ".kn-chip.on{border-color:var(--a);background:var(--a);color:#fff}" +
      ".kn-live{margin:16px 0;text-align:center}.kn-live-price{font-size:2rem;font-weight:700;font-variant-numeric:tabular-nums}" +
      ".kn-actions{display:flex;flex-direction:column;gap:8px;margin:16px 0}" +
      ".kn-btn{min-height:48px;padding:0 24px;border-radius:4px;font:inherit;font-weight:600;border:1px solid transparent;cursor:pointer;text-transform:uppercase;letter-spacing:.04em}" +
      ".kn-btn-primary{background:var(--a);color:#fff}.kn-btn-primary:hover{background:var(--ai)}" +
      ".kn-btn-ghost{background:transparent;color:var(--ai);border-color:var(--line)}" +
      ".kn-link{background:none;border:0;color:var(--ai);text-decoration:underline;cursor:pointer;font:inherit;padding:4px 0}" +
      ".kn-back{background:none;border:0;color:var(--muted);cursor:pointer;font:inherit;margin-top:8px}" +
      ".kn-badge{display:inline-block;font-size:.72rem;background:var(--blush);color:var(--ai);border-radius:20px;padding:1px 8px}" +
      ".kn-reassure{background:var(--royal-soft);border-left:3px solid var(--a);padding:8px 12px;border-radius:0 4px 4px 0;font-size:.875rem;color:var(--ai)}" +
      ".kn-alert{background:#F7E4E2;color:var(--alert);padding:12px;border-radius:4px}" +
      ".kn-ticket{font-variant-numeric:tabular-nums;border:1px solid var(--line);border-radius:6px;padding:12px;background:var(--surface)}" +
      ".kn-line{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed var(--line)}" +
      ".kn-line-r{display:flex;flex-direction:column;align-items:flex-end;gap:4px}" +
      ".kn-total{border-bottom:0;border-top:2px solid var(--ink);font-weight:700;font-size:1.15rem;margin-top:4px}" +
      ".kn-ok{color:var(--ok)}" +
      ".kn-dates{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px}" +
      ".kn-date{white-space:nowrap;min-height:44px;padding:0 12px;border:1px solid var(--line);border-radius:4px;background:var(--surface);font:inherit;cursor:pointer}" +
      ".kn-date.on{border-color:var(--a);color:var(--a);font-weight:700}" +
      // Hauteur plafonnée + défilement interne : une journée avec beaucoup de
      // créneaux libres reste scannable sans faire défiler toute la page (le
      // sélecteur de date et le bouton Continuer restent visibles).
      ".kn-slot-list{display:grid;gap:8px;margin-top:8px;max-height:min(50vh,420px);overflow-y:auto;padding-right:2px}" +
      ".kn-slot-heading{font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;margin:8px 0 2px}" +
      ".kn-slot-heading:first-child{margin-top:0}" +
      ".kn-slot{min-height:48px;border:1px solid var(--line);border-radius:4px;background:var(--surface);font:inherit;cursor:pointer}" +
      ".kn-slot.on{border-color:var(--a);background:var(--a);color:#fff}" +
      // Barre panier : fixe en bas du vrai viewport, ancrée indépendamment de
      // tout calcul de hauteur (voir note .kn-scroller ci-dessus). pointer-
      // events:none sur le conteneur (vide la plupart du temps) pour ne
      // jamais bloquer de clics sous lui ; ré-activés sur le bouton lui-même.
      // --kn-vv-bottom (setupViewportGuard()) compense la barre d'outils
      // dynamique de Safari iOS, qu'env(safe-area-inset-bottom) ne couvre pas.
      ".kn-quotebar-slot{position:fixed;left:0;right:0;bottom:var(--kn-vv-bottom,0px);z-index:20;padding:8px 16px max(8px,env(safe-area-inset-bottom));pointer-events:none}" +
      ".kn-quotebar-slot:empty{display:none}" +
      ".kn-quotebar{pointer-events:auto;width:100%;max-width:560px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;min-height:56px;padding:0 16px;background:var(--ink);color:#fff;border:0;border-radius:6px;font:inherit;cursor:pointer;box-shadow:0 4px 16px rgba(27,42,74,.25)}" +
      ".kn-quotebar-total{font-weight:700;font-size:1.15rem;font-variant-numeric:tabular-nums}" +
      ".kn-flash{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--ink);color:#fff;padding:12px 20px;border-radius:4px;z-index:9999}" +
      ".kn-done{text-align:center;padding:24px 0}.kn-done-mark{width:64px;height:64px;line-height:64px;border-radius:50%;background:var(--ok);color:#fff;font-size:2rem;margin:0 auto 16px}" +
      "@media(prefers-reduced-motion:reduce){.kn *{scroll-behavior:auto!important}}"
    );
  }
})();
