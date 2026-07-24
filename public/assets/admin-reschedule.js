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
    var palette = ["#F7D7E2", "#E7ECFB", "#D8F0DF", "#FCE8C8", "#E4D9F7", "#D6EFF3"];
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
    var form = root.querySelector("#kn-resched-form");

    var today = new Date();
    var viewYear = today.getFullYear();
    var viewMonth = today.getMonth(); // 0-indexed
    var selectedDate = null;
    var lastSlots = {}; // "H:i" => [{id,name}, ...]

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

    function loadMonth() {
      var ym = viewYear + "-" + pad(viewMonth + 1);
      monthLabel.textContent = MONTHS[viewMonth] + " " + viewYear;
      fetch("/admin/job/" + jobId + "/creneaux/mois?month=" + ym)
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          renderCalendar(data.dates || []);
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
        var dateStr = viewYear + "-" + pad(viewMonth + 1) + "-" + pad(day);
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "kn-resched-cal-day";
        btn.textContent = String(day);
        var isPast = dateStr < todayStr;
        var hasSlots = !!availSet[dateStr];
        if (isPast || !hasSlots) {
          btn.disabled = true;
        } else {
          btn.addEventListener("click", function (ds) {
            return function () {
              selectDate(ds, btn);
            };
          }(dateStr));
        }
        calGrid.appendChild(btn);
      }
    }

    function selectDate(dateStr, btnEl) {
      selectedDate = dateStr;
      calGrid.querySelectorAll(".kn-resched-cal-day").forEach(function (b) {
        b.classList.remove("is-selected");
      });
      btnEl.classList.add("is-selected");

      slotList.innerHTML = '<p class="kn-muted">Chargement…</p>';
      fetch("/admin/job/" + jobId + "/creneaux?date=" + dateStr)
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          lastSlots = data.slots || {};
          renderSlots();
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
            var av = document.createElement("span");
            av.className = "kn-avatar";
            av.style.background = avatarColor(t.id);
            av.title = t.name;
            av.textContent = initials(t.name);
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
            selectSlot(time, techs, row);
          });
          slotList.appendChild(row);
        });
      });

      if (shown === 0) {
        slotList.innerHTML = '<p class="kn-muted">Aucun créneau libre ce jour-là.</p>';
      }
    }

    function selectSlot(time, techs, rowEl) {
      slotList.querySelectorAll(".kn-resched-slot").forEach(function (r) {
        r.classList.remove("is-selected");
      });
      rowEl.classList.add("is-selected");

      // Préfère le technicien déjà assigné s'il est libre à ce créneau,
      // sinon le premier technicien libre proposé.
      var chosen = techs.find(function (t) { return t.id === currentTech; }) || techs[0];

      startInput.value = selectedDate + "T" + time;
      techInput.value = String(chosen.id);
      form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
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
