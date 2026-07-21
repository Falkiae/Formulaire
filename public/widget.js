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

  // --- Shadow DOM & styles ---------------------------------------------------
  var shadow = mount.attachShadow ? mount.attachShadow({ mode: "open" }) : mount;
  var root = document.createElement("div");
  root.className = "kn";
  var style = document.createElement("style");
  style.textContent = CSS();
  shadow.appendChild(style);
  shadow.appendChild(root);

  // Étapes nommées (barre de progression).
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

  // --- Rendu principal -------------------------------------------------------
  function render() {
    save();
    root.innerHTML = "";
    root.appendChild(progressBar());
    var body = el('<div class="kn-body"></div>');
    root.appendChild(body);

    var step = state.step;
    if (step === "where") renderWhere(body);
    else if (step === "what") renderWhat(body);
    else if (step === "details") renderDetails(body);
    else if (step === "cart") renderCart(body);
    else if (step === "intake") renderIntake(body);
    else if (step === "contact") renderContact(body);
    else if (step === "slot") renderSlot(body);
    else if (step === "recap") renderRecap(body);
    else if (step === "done") renderDone(body);

    if (state.cart && state.cart.item_count > 0 && step !== "done") {
      root.appendChild(quoteBar());
    }
  }

  function goto(step) {
    state.step = step;
    render();
    root.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function progressBar() {
    var idx = STEPS.findIndex(function (s) {
      return s.key === state.step;
    });
    var wrap = el('<div class="kn-progress" role="navigation" aria-label="Étapes"></div>');
    // Pastilles des choix déjà faits (cliquables pour revenir).
    var pills = el('<div class="kn-pills"></div>');
    if (state.mode) {
      pills.appendChild(pill(state.mode === "onsite" ? "Chez vous" : "Atelier", "where"));
    }
    if (state.serviceId && catalog) {
      var svc = catalog.services.find(function (s) {
        return s.id === state.serviceId;
      });
      if (svc) pills.appendChild(pill(svc.name, "what"));
    }
    wrap.appendChild(pills);
    var bar = el('<div class="kn-steps"></div>');
    STEPS.forEach(function (s, i) {
      var b = el('<span class="kn-step ' + (i <= idx ? "on" : "") + '">' + esc(s.label) + "</span>");
      bar.appendChild(b);
    });
    wrap.appendChild(bar);
    return wrap;
  }
  function pill(text, step) {
    var p = el('<button class="kn-pill" type="button">' + esc(text) + " ✕</button>");
    p.addEventListener("click", function () {
      goto(step);
    });
    return p;
  }

  // --- Étape 1 : OÙ (code postal + mode) ------------------------------------
  function renderWhere(body) {
    body.appendChild(el('<h2 class="kn-h">Où souhaitez-vous être nettoyé ?</h2>'));
    var field = el(
      '<div class="kn-field"><label for="kn-postal">Votre code postal</label>' +
        '<input id="kn-postal" inputmode="numeric" autocomplete="postal-code" maxlength="4" value="' +
        esc(state.postal) +
        '" placeholder="Ex. 4000"></div>'
    );
    body.appendChild(field);
    var input = field.querySelector("input");
    input.addEventListener("input", function () {
      state.postal = input.value.replace(/\D/g, "").slice(0, 4);
    });

    var cards = el('<div class="kn-cards"></div>');
    cards.appendChild(
      choiceCard("🏠", "Je veux qu'on vienne chez moi", "Un technicien se déplace à votre adresse.", function () {
        pickMode("onsite");
      }, state.mode === "onsite")
    );
    cards.appendChild(
      choiceCard("🔧", "Je viens à l'atelier", "Vous déposez, nous nettoyons. Souvent moins cher.", function () {
        pickMode("workshop");
      }, state.mode === "workshop")
    );
    body.appendChild(cards);
    body.appendChild(reassure("Oui, nous intervenons à Liège et dans un rayon de 25 km."));
  }
  function pickMode(mode) {
    if (!state.postal || state.postal.length < 4) {
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
        render();
      });
      return;
    }
    var cards = el('<div class="kn-cards"></div>');
    if (!state.categoryId) {
      catalog.categories.forEach(function (cat) {
        cards.appendChild(
          choiceCard("📦", cat.name, cat.description || "", function () {
            state.categoryId = cat.id;
            // Descend d'un niveau si sous-catégories, sinon services.
            render();
          })
        );
      });
    } else {
      var cat = findCategory(state.categoryId);
      (cat && cat.children && cat.children.length ? cat.children : [cat]).forEach(function (c) {
        catalog.services
          .filter(function (s) {
            return s.category_id === c.id;
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
              })
            );
          });
      });
      body.appendChild(backLink(function () {
        state.categoryId = null;
        render();
      }));
    }
    body.appendChild(cards);
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
        render();
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
            livePrice();
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
        var row = el(
          '<label class="kn-extra"><span>' +
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
          livePrice();
        });
        list.appendChild(row);
      });
      body.appendChild(list);
    }

    body.appendChild(reassure("Prix ferme. Aucun supplément le jour de l'intervention."));

    var live = el('<div class="kn-live" id="kn-live"></div>');
    body.appendChild(live);
    livePrice();

    var actions = el('<div class="kn-actions"></div>');
    var add = el('<button class="kn-btn kn-btn-primary" type="button">Ajouter au panier</button>');
    add.addEventListener("click", addToCart);
    actions.appendChild(add);
    body.appendChild(actions);
    body.appendChild(backLink(function () {
      goto("what");
    }));
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
  function livePrice() {
    var box = shadow.getElementById("kn-live");
    if (!box) return;
    var line = {
      service_id: state.serviceId,
      mode: state.mode,
      variant_id: state.variantId || 0,
      extra_ids: state.extraIds,
      quantity: state.quantity,
    };
    api("/quote", { method: "POST", body: { lines: [line] } })
      .then(function (q) {
        box.innerHTML =
          '<div class="kn-live-price">' +
          q.total_tvac_formatted +
          '</div><div class="kn-muted">TVAC · durée estimée ' +
          q.total_active_duration_min +
          " min</div>";
      })
      .catch(function () {
        box.innerHTML = "";
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
      api("/cart/" + state.token + "/items", { method: "POST", body: body }).then(function (snap) {
        state.cart = snap;
        state.serviceId = null;
        state.variantId = null;
        state.extraIds = [];
        state._serviceConfig = null;
        goto("cart");
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
      var cont = el('<button class="kn-btn kn-btn-primary" type="button">Continuer</button>');
      cont.addEventListener("click", function () {
        goto("intake");
      });
      actions.appendChild(more);
      actions.appendChild(cont);
      body.appendChild(actions);
    });
  }

  // --- Étape 5 : QUESTIONS D'INTAKE -----------------------------------------
  function renderIntake(body) {
    body.appendChild(el('<h2 class="kn-h">Quelques précisions</h2>'));
    var hasOnsite = cartHasMode("onsite");
    var q = [];
    if (hasOnsite) {
      q.push(radioQ("water_access", "Avez-vous un accès à l'eau ?", ["Oui", "Non"]));
      q.push(radioQ("power_access", "Avez-vous un accès à l'électricité ?", ["Oui", "Non"]));
    }
    q.push(radioQ("pets", "Avez-vous des animaux ?", ["Oui", "Non"]));
    q.push(dirtScale());
    q.forEach(function (n) {
      body.appendChild(n);
    });
    body.appendChild(reassure("Si l'état diffère, on vous prévient avant de commencer. Vous restez libre de refuser."));
    var actions = el('<div class="kn-actions"></div>');
    var cont = el('<button class="kn-btn kn-btn-primary" type="button">Continuer</button>');
    cont.addEventListener("click", function () {
      goto("contact");
    });
    actions.appendChild(cont);
    body.appendChild(actions);
    body.appendChild(backLink(function () {
      goto("cart");
    }));
  }
  function radioQ(key, label, opts) {
    var wrap = el('<div class="kn-field"><label>' + esc(label) + "</label></div>");
    var group = el('<div class="kn-choice-row"></div>');
    opts.forEach(function (o) {
      var val = o.toLowerCase() === "oui" ? "yes" : "no";
      var b = el('<button type="button" class="kn-chip ' + (state.answers[key] === val ? "on" : "") + '">' + esc(o) + "</button>");
      b.addEventListener("click", function () {
        state.answers[key] = val;
        group.querySelectorAll(".kn-chip").forEach(function (c) {
          c.classList.remove("on");
        });
        b.classList.add("on");
      });
      group.appendChild(b);
    });
    wrap.appendChild(group);
    return wrap;
  }
  function dirtScale() {
    var wrap = el('<div class="kn-field"><label>Quel est l\'état de salissure ?</label></div>');
    var row = el('<div class="kn-choice-row"></div>');
    [
      ["light", "Léger"],
      ["marked", "Marqué"],
      ["deep", "Profond"],
    ].forEach(function (o) {
      var b = el('<button type="button" class="kn-chip ' + (state.answers.dirt_level === o[0] ? "on" : "") + '">' + esc(o[1]) + "</button>");
      b.addEventListener("click", function () {
        state.answers.dirt_level = o[0];
        row.querySelectorAll(".kn-chip").forEach(function (c) {
          c.classList.remove("on");
        });
        b.classList.add("on");
      });
      row.appendChild(b);
    });
    wrap.appendChild(row);
    return wrap;
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
    body.appendChild(backLink(function () {
      goto("intake");
    }));
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
          body.appendChild(el('<p class="kn-alert">Nous n\'intervenons pas encore à cette adresse. Laissez-nous vos coordonnées, nous vous recontactons.</p>'));
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
      body.appendChild(backLink(function () {
        goto("contact");
      }));
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
      body.appendChild(backLink(function () {
        goto("slot");
      }));
    });
  }
  function submitBooking() {
    var payload = {
      token: state.token,
      customer: state.customer,
      address: state.address,
      slots: state.slots,
      answers: state.answers,
      consent_terms: !!state._consent,
      hp: "",
    };
    api("/bookings", { method: "POST", body: payload })
      .then(function (res) {
        state.booking = res;
        goto("done");
        reset(); // panier consommé
      })
      .catch(function (e) {
        if (e.status === 409) flash("Ce créneau vient d'être réservé. Merci d'en choisir un autre.");
        else flash(e.message || "Une erreur est survenue.");
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
  function choiceCard(icon, title, desc, onClick, active) {
    var c = el(
      '<button type="button" class="kn-card ' +
        (active ? "on" : "") +
        '">' +
        (icon ? '<span class="kn-card-icon" aria-hidden="true">' + icon + "</span>" : "") +
        '<span class="kn-card-title">' +
        esc(title) +
        "</span>" +
        (desc ? '<span class="kn-card-desc">' + esc(desc) + "</span>" : "") +
        "</button>"
    );
    c.addEventListener("click", onClick);
    return c;
  }
  function reassure(text) {
    return el('<p class="kn-reassure">' + esc(text) + "</p>");
  }
  function backLink(onClick) {
    var b = el('<button type="button" class="kn-back">← Revenir</button>');
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
  render();

  // --- CSS (tokens de marque inline, scopé au Shadow DOM) -------------------
  function CSS() {
    return (
      ".kn{--a:#586FF3;--ai:#3A4BC0;--blush:#F7D7E2;--ink:#141A2E;--muted:#5C6479;--paper:#FBFBFD;--surface:#fff;--line:#E4E6EF;--ok:#1D7A54;--alert:#B4322D;" +
      "font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:16px;line-height:1.6;color:var(--ink);background:var(--paper);max-width:560px;margin:0 auto;padding:16px;box-sizing:border-box}" +
      ".kn *{box-sizing:border-box}" +
      ".kn-h{font-size:1.5rem;margin:8px 0 16px}.kn-h3{font-size:1.05rem;margin:16px 0 8px}" +
      ".kn-muted{color:var(--muted);font-size:.875rem}" +
      ".kn-progress{margin-bottom:16px}" +
      ".kn-pills{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}" +
      ".kn-pill{background:var(--blush);color:var(--ai);border:0;border-radius:20px;padding:4px 12px;font-size:.8rem;cursor:pointer}" +
      ".kn-steps{display:flex;gap:4px;flex-wrap:wrap}" +
      ".kn-step{font-size:.72rem;color:var(--muted);padding:2px 6px;border-bottom:2px solid var(--line)}" +
      ".kn-step.on{color:var(--a);border-color:var(--a);font-weight:700}" +
      ".kn-cards{display:grid;gap:12px;margin:12px 0}.kn-cards-sm{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}" +
      ".kn-card{display:flex;flex-direction:column;gap:4px;text-align:left;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:16px;min-height:48px;cursor:pointer;font:inherit;color:inherit}" +
      ".kn-card.on{border-color:var(--a);box-shadow:0 0 0 2px var(--a) inset}" +
      ".kn-card-icon{font-size:1.5rem}.kn-card-title{font-weight:700}.kn-card-desc{color:var(--muted);font-size:.85rem}" +
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
      ".kn-quotebar{position:sticky;bottom:0;width:100%;display:flex;justify-content:space-between;align-items:center;min-height:56px;padding:0 16px;margin-top:16px;background:var(--ink);color:#fff;border:0;border-radius:12px;font:inherit;cursor:pointer}" +
      ".kn-quotebar-total{font-weight:700;font-size:1.15rem;font-variant-numeric:tabular-nums}" +
      ".kn-flash{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--ink);color:#fff;padding:12px 20px;border-radius:8px;z-index:9999}" +
      ".kn-done{text-align:center;padding:24px 0}.kn-done-mark{width:64px;height:64px;line-height:64px;border-radius:50%;background:var(--ok);color:#fff;font-size:2rem;margin:0 auto 16px}" +
      "@media(prefers-reduced-motion:reduce){.kn *{scroll-behavior:auto!important}}"
    );
  }
})();
