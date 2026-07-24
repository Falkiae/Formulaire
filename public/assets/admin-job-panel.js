(function () {
  "use strict";

  var overlay, slot;

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
    loadJob(m[1], link.getAttribute("href"));
  });

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
      });
  }
})();
