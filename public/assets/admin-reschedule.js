(function () {
  "use strict";

  var MONTHS = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];
  var WEEKDAYS = ["Lu", "Ma", "Me", "Je", "Ve", "Sa", "Di"];

  function initials(name) {
    var parts = name.trim().split(/\s+/);
    var first = parts[0] ? parts[0][0] : "";
    var last = parts.length > 1 ? parts[parts.length - 1][0] : "";
    return (first + last).toUpperCase();
  }

  function avatarColor(id) {
    var palette = ["#EFC6CB", "#E5E9FB", "#E4EFE7", "#F6EDD8", "#DDE3EF", "#E4AEB7"];
    return palette[id % palette.length];
  }

  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  function initPicker(root) {
    var toggleBtn = root.querySelector("#kn-open-resched");
    var panel = root.querySelector("#kn-resched-panel");
    var picker = root.querySelector("#kn-resched-picker");
    if (!toggleBtn || !panel || !picker) return;

    var jobId = picker.getAttribute("data-job-id");
    var currentTech = parseInt(picker.getAttribute("data-current-tech"), 10) || 0;
    var calGrid = root.querySelector("#kn-resched-cal-grid");
    var monthLabel = root.querySelector("#kn-resched-month");
    var prevBtn = root.querySelector("#kn-resched-prev");
    var nextBtn = root.querySelector("#kn-resched-next");
    var slotList = root.querySelector("#kn-resched-slot-list");
    var onlyCurrent = root.querySelector("#kn-resched-only-current");
    var startInput = root.querySelector("#kn-resched-start");
    var techInput = root.querySelector("#kn-resched-tech");
    var modeInput = root.querySelector("#kn-resched-mode-input");
    var addressInput = root.querySelector("#kn-resched-address-input");
    var bayInput = root.querySelector("#kn-resched-bay-input");
    var form = root.querySelector("#kn-resched-form");
    var confirmBtn = root.querySelector("#kn-resched-confirm");
    var modeToggle = root.querySelectorAll('input[name="kn-resched-target-mode"]');
    var addressSelect = root.querySelector("#kn-resched-address-select");

    var today = new Date();
    var viewYear = today.getFullYear();
    var viewMonth = today.getMonth(); // 0-indexed
    var selectedDate = null;
    var lastSlots = {}; // "H:i" => [{id,name,bay_id?}, ...]
    var targetMode = picker.getAttribute("data-current-mode") || "onsite";
    var defaultAddressId = parseInt(picker.getAttribute("data-default-address-id"), 10) || 0;
    var pending = null; // {time, tech}

    function resolvedAddressId() {
      if (addressSelect && addressSelect.value) return addressSelect.value;
      return defaultAddressId ? String(defaultAddressId) : "0";
    }

    function setPending(next) {
      pending = next;
      if (confirmBtn) {
        confirmBtn.disabled = !pending;
        confirmBtn.textContent = pending
          ? "Confirmer — " + pending.tech.name + ", " + pending.time
          : "Confirmer la replanification";
      }
    }

    toggleBtn.addEventListener("click", function () {
      var hidden = panel.hasAttribute("hidden");
      if (hidden) {
        panel.removeAttribute("hidden");
        loadMonth();
      } else {
        panel.setAttribute("hidden", "");
      }
    });

    prevBtn.addEventListener("click", function () {
      viewMonth--;
      if (viewMonth < 0) {
        viewMonth = 11;
        viewYear--;
      }
      loadMonth();
    });
    nextBtn.addEventListener("click", function () {
      viewMonth++;
      if (viewMonth > 11) {
        viewMonth = 0;
        viewYear++;
      }
      loadMonth();
    });

    onlyCurrent.addEventListener("change", renderSlots);

    modeToggle.forEach(function (radio) {
      radio.addEventListener("change", function () {
        if (!radio.checked) return;
        targetMode = radio.value;
        if (addressSelect) {
          addressSelect.style.display = targetMode === "onsite" ? "" : "none";
        }
        setPending(null);
        slotList.innerHTML = "";
        loadMonth();
      });
    });
    if (addressSelect) {
      addressSelect.addEventListener("change", function () {
        setPending(null);
        var sel = calGrid.querySelector(".kn-resched-cal-day.is-selected");
        if (selectedDate && sel) selectDate(selectedDate, sel);
      });
    }

    function querySuffix() {
      var suffix = "&mode=" + encodeURIComponent(targetMode);
      if (targetMode === "onsite") {
        suffix += "&address_id=" + encodeURIComponent(resolvedAddressId());
      }
      return suffix;
    }

    function fetchJson(url) {
      return fetch(url, { headers: { Accept: "application/json" } }).then(function (r) {
        return r
          .json()
          .catch(function () {
            return null;
          })
          .then(function (body) {
            if (!r.ok) {
              var err = new Error((body && body.error) || "http_" + r.status);
              err.serverMessage = body && body.error;
              throw err;
            }
            return body;
          });
      });
    }

    function errorMessage(err) {
      return (err && err.serverMessage) || "Impossible de charger les disponibilités.";
    }

    function showError(container, err) {
      container.innerHTML = "";
      var p = document.createElement("p");
      p.className = "kn-muted";
      p.textContent = errorMessage(err);
      container.appendChild(p);
    }

    function loadMonth() {
      var ym = viewYear + "-" + pad(viewMonth + 1);
      monthLabel.textContent = MONTHS[viewMonth] + " " + viewYear;
      calGrid.innerHTML = '<p class="kn-muted">Chargement…</p>';
      fetchJson("/admin/job/" + jobId + "/creneaux/mois?month=" + ym + querySuffix())
        .then(function (data) {
          renderCalendar(data.dates || []);
        })
        .catch(function (err) {
          showError(calGrid, err);
        });
    }

    function renderCalendar(availableDates) {
      calGrid.innerHTML = "";
      WEEKDAYS.forEach(function (d) {
        var h = document.createElement("div");
        h.className = "kn-muted";
        h.style.textAlign = "center";
        h.style.fontSize = ".7rem";
        h.textContent = d;
        calGrid.appendChild(h);
      });

      var first = new Date(viewYear, viewMonth, 1);
      var startOffset = (first.getDay() + 6) % 7; // lundi = 0
      for (var i = 0; i < startOffset; i++) {
        calGrid.appendChild(document.createElement("div"));
      }

      var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
      var todayStr = today.getFullYear() + "-" + pad(today.getMonth() + 1) + "-" + pad(today.getDate());
      var availSet = {};
      availableDates.forEach(function (d) {
        availSet[d] = true;
      });

      for (var day = 1; day <= daysInMonth; day++) {
        // `let` : liaison neuve à chaque itération, capturée correctement par
        // le gestionnaire de clic (contrairement à `var`, qui aurait fait
        // pointer tous les clics vers le dernier jour généré par la boucle).
        let dateStr = viewYear + "-" + pad(viewMonth + 1) + "-" + pad(day);
        let btn = document.createElement("button");
        btn.type = "button";
        btn.className = "kn-resched-cal-day";
        btn.textContent = String(day);
        var isPast = dateStr < todayStr;
        var hasSlots = !!availSet[dateStr];
        if (isPast || !hasSlots) {
          btn.disabled = true;
        } else {
          btn.addEventListener("click", function () {
            selectDate(dateStr, btn);
          });
        }
        calGrid.appendChild(btn);
      }
    }

    function selectDate(dateStr, btnEl) {
      selectedDate = dateStr;
      setPending(null);
      calGrid.querySelectorAll(".kn-resched-cal-day").forEach(function (b) {
        b.classList.remove("is-selected");
      });
      btnEl.classList.add("is-selected");

      slotList.innerHTML = '<p class="kn-muted">Chargement…</p>';
      fetchJson("/admin/job/" + jobId + "/creneaux?date=" + dateStr + querySuffix())
        .then(function (data) {
          lastSlots = data.slots || {};
          renderSlots();
        })
        .catch(function (err) {
          showError(slotList, err);
        });
    }

    function timeOfDay(time) {
      var h = parseInt(time.split(":")[0], 10);
      if (h < 12) return "Matin";
      if (h < 18) return "Après-midi";
      return "Soir";
    }

    // Matin/après-midi/soir : une journée chargée (plusieurs techniciens ×
    // créneaux de 30 min) reste scannable au lieu d'un mur de boutons.
    function renderSlots() {
      slotList.innerHTML = "";
      var times = Object.keys(lastSlots).sort();
      var onlyCurrentChecked = onlyCurrent.checked;
      var buckets = { "Matin": [], "Après-midi": [], "Soir": [] };

      times.forEach(function (time) {
        var techs = lastSlots[time];
        if (onlyCurrentChecked && !techs.some(function (t) { return t.id === currentTech; })) {
          return;
        }
        buckets[timeOfDay(time)].push(time);
      });

      var shown = 0;
      ["Matin", "Après-midi", "Soir"].forEach(function (heading) {
        var bucketTimes = buckets[heading];
        if (!bucketTimes.length) return;

        var h = document.createElement("div");
        h.className = "kn-slot-heading";
        h.textContent = heading;
        slotList.appendChild(h);

        bucketTimes.forEach(function (time) {
          var techs = lastSlots[time];
          shown++;

          var row = document.createElement("button");
          row.type = "button";
          row.className = "kn-resched-slot";

          var left = document.createElement("span");
          left.textContent = time;
          row.appendChild(left);

          var avatars = document.createElement("span");
          avatars.className = "kn-resched-slot-avatars";
          techs.slice(0, 4).forEach(function (t) {
            var av = document.createElement("button");
            av.type = "button";
            av.className = "kn-avatar kn-avatar-pick";
            av.style.background = avatarColor(t.id);
            av.title = "Assigner à " + t.name;
            av.textContent = initials(t.name);
            av.addEventListener("click", function (evt) {
              evt.stopPropagation();
              selectSlot(time, t, row, av);
            });
            avatars.appendChild(av);
          });
          var count = document.createElement("span");
          count.className = "kn-muted";
          count.style.fontSize = ".72rem";
          count.style.marginLeft = "4px";
          count.textContent = techs.length + " libre" + (techs.length > 1 ? "s" : "");
          avatars.appendChild(count);
          row.appendChild(avatars);

          row.addEventListener("click", function () {
            // Sélection rapide : technicien déjà assigné s'il est libre à ce
            // créneau, sinon le premier proposé. Cliquer un avatar précis
            // (ci-dessus) choisit explicitement CE technicien à la place.
            var preferred = techs.find(function (t) { return t.id === currentTech; }) || techs[0];
            selectSlot(time, preferred, row, null);
          });
          slotList.appendChild(row);
        });
      });

      if (shown === 0) {
        slotList.innerHTML = '<p class="kn-muted">Aucun créneau libre ce jour-là.</p>';
      }
    }

    // Sélection sans soumission — le bouton "Confirmer" déclenche l'envoi
    // réel, pour laisser le temps de choisir le technicien voulu et éviter
    // toute replanification accidentelle au simple clic.
    function selectSlot(time, tech, rowEl, avatarEl) {
      slotList.querySelectorAll(".kn-resched-slot").forEach(function (r) {
        r.classList.remove("is-selected");
      });
      slotList.querySelectorAll(".kn-avatar-pick").forEach(function (a) {
        a.classList.remove("is-selected");
      });
      rowEl.classList.add("is-selected");
      if (avatarEl) avatarEl.classList.add("is-selected");

      setPending({ time: time, tech: tech });
    }

    if (confirmBtn) {
      confirmBtn.addEventListener("click", function () {
        if (!pending || !selectedDate) return;
        startInput.value = selectedDate + "T" + pending.time;
        techInput.value = String(pending.tech.id);
        if (modeInput) modeInput.value = targetMode;
        if (addressInput) addressInput.value = targetMode === "onsite" ? resolvedAddressId() : "0";
        if (bayInput) bayInput.value = pending.tech.bay_id ? String(pending.tech.bay_id) : "0";
        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
      });
    }
  }

  function initMap(root) {
    var el = root.querySelector("#kn-job-map");
    if (!el || !window.L) return;
    if (el.dataset.knMapInit) return;
    el.dataset.knMapInit = "1";
    var lat = parseFloat(el.getAttribute("data-lat"));
    var lng = parseFloat(el.getAttribute("data-lng"));
    if (isNaN(lat) || isNaN(lng)) return;
    var map = window.L.map(el).setView([lat, lng], 15);
    window.L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "&copy; OpenStreetMap",
      maxZoom: 19,
    }).addTo(map);
    window.L.marker([lat, lng]).addTo(map);
  }

  window.KeepnewReschedule = { init: initPicker };
  window.KeepnewJobMap = { init: initMap };
})();
