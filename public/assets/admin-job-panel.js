(function () {
  "use strict";

  var overlay, slot;
  var backgroundUrl = null;
  // Sélecteurs des zones de contenu à rafraîchir en arrière-plan après une
  // action du panneau — dans cet ordre, la première page (dispatch,
  // calendrier mois, calendrier semaine) qui matche des deux côtés (page
  // vivante + page fraîchement récupérée) est utilisée.
  var BACKGROUND_REGIONS = [".kn-dispatch", ".kn-cal-main"];

  function ensureDom() {
    if (slot) return;
    overlay = document.createElement("div");
    overlay.className = "kn-job-panel-overlay";
    document.body.appendChild(overlay);
    slot = document.createElement("div");
    slot.className = "kn-job-panel-slot";
    document.body.appendChild(slot);

    overlay.addEventListener("click", function () {
      closePanel();
    });
    slot.addEventListener("click", function (e) {
      if (e.target.closest(".kn-job-panel-close")) closePanel();
    });
    // Formulaires du panneau : soumis en fetch pour rafraîchir sur place
    // (jamais sur la page complète — cf. admin-reschedule.js, qui n'intercepte
    // rien hors de ce conteneur créé dynamiquement).
    slot.addEventListener("submit", onPanelSubmit);
  }

  function enhance(root) {
    if (window.KeepnewReschedule) window.KeepnewReschedule.init(root);
    if (window.KeepnewJobMap) window.KeepnewJobMap.init(root);
  }

  function openPanel(html, url) {
    ensureDom();
    slot.innerHTML = html;
    overlay.classList.add("is-open");
    slot.classList.add("is-open");
    document.body.style.overflow = "hidden";
    enhance(slot);
    if (url) history.pushState({ knJobPanel: true }, "", url);
  }

  function closePanel(skipHistory) {
    backgroundUrl = null;
    if (!slot) return;
    overlay.classList.remove("is-open");
    slot.classList.remove("is-open");
    document.body.style.overflow = "";
    if (!skipHistory && window.history.state && window.history.state.knJobPanel) {
      history.back();
    }
  }

  function loadJob(id, url) {
    fetch("/admin/job/" + id + "?partial=1")
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        openPanel(html, url);
      });
  }

  document.addEventListener("click", function (e) {
    var link = e.target.closest('a[href^="/admin/job/"]');
    if (!link) return;
    var m = link.getAttribute("href").match(/^\/admin\/job\/(\d+)/);
    if (!m) return;
    e.preventDefault();
    if (!backgroundUrl) backgroundUrl = location.href;
    loadJob(m[1], link.getAttribute("href"));
  });

  /**
   * Rafraîchit discrètement, en tâche de fond, la zone de contenu de la page
   * calendrier/dispatch derrière le panneau (jamais visible tant que le
   * panneau reste ouvert plein écran sur mobile) — best-effort pur : toute
   * absence/échec est ignoré en silence, ne doit jamais gêner le flux du
   * panneau lui-même.
   */
  function refreshBackground() {
    if (!backgroundUrl) return;
    fetch(backgroundUrl)
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        var fresh = new DOMParser().parseFromString(html, "text/html");
        for (var i = 0; i < BACKGROUND_REGIONS.length; i++) {
          var selector = BACKGROUND_REGIONS[i];
          var live = document.querySelector(selector);
          var next = fresh.querySelector(selector);
          if (live && next) {
            var scrollTop = live.scrollTop;
            var scrollLeft = live.scrollLeft;
            live.innerHTML = next.innerHTML;
            live.scrollTop = scrollTop;
            live.scrollLeft = scrollLeft;
            return;
          }
        }
      })
      .catch(function () {
        // Best-effort : rien à faire, l'utilisateur retombera sur un
        // rechargement manuel comme avant si besoin.
      });
  }

  window.addEventListener("popstate", function () {
    closePanel(true);
  });

  // Page complète (repli sans panneau, ex. navigation directe ou mobile) :
  // active aussi la carte / le sélecteur de créneaux sur le contenu déjà
  // présent dans la page (pas de fetch, rien à ouvrir).
  document.addEventListener("DOMContentLoaded", function () {
    if (document.querySelector(".kn-job-panel")) enhance(document);
  });

  function onPanelSubmit(e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    // Respecte un éventuel `onsubmit="return confirm(...)"` sur le
    // formulaire (ex. boutons d'annulation) : si l'utilisateur a annulé la
    // confirmation, le navigateur a déjà appelé preventDefault() avant que
    // cet événement ne remonte jusqu'ici — ne pas soumettre quand même.
    if (e.defaultPrevented) return;
    e.preventDefault();
    var fd = new FormData(form);
    fd.set("ajax", "1");
    fetch(form.getAttribute("action"), { method: "POST", body: fd })
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        slot.innerHTML = html;
        enhance(slot);
        refreshBackground();
      });
  }
})();
