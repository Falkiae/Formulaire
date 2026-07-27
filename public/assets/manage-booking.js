(function () {
  "use strict";

  var MONTHS = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];
  var WEEKDAYS = ["Lu", "Ma", "Me", "Je", "Ve", "Sa", "Di"];

  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  function initPanel(panel, openBtn) {
    var jobId = panel.getAttribute("data-job-id");
    var token = panel.getAttribute("data-token");
    var calGrid = panel.querySelector(".kn-manage-cal-grid");
    var monthLabel = panel.querySelector(".kn-manage-month");
    var prevBtn = panel.querySelector(".kn-manage-prev");
    var nextBtn = panel.querySelector(".kn-manage-next");
    var slotList = panel.querySelector(".kn-manage-slot-list");
    var form = panel.querySelector(".kn-manage-resched-form");
    var dateInput = form.querySelector(".kn-manage-date-input");
    var timeInput = form.querySelector(".kn-manage-time-input");

    var today = new Date();
    var viewYear = today.getFullYear();
    var viewMonth = today.getMonth();

    openBtn.addEventListener("click", function () {
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

    function fetchJson(url) {
      return fetch(url, { headers: { Accept: "application/json" } }).then(function (r) {
        return r.json();
      });
    }

    function baseUrl() {
      return "/rdv/" + encodeURIComponent(token);
    }

    function loadMonth() {
      var ym = viewYear + "-" + pad(viewMonth + 1);
      monthLabel.textContent = MONTHS[viewMonth] + " " + viewYear;
      calGrid.innerHTML = '<p class="kn-muted">Chargement…</p>';
      fetchJson(baseUrl() + "/creneaux/mois?job_id=" + encodeURIComponent(jobId) + "&month=" + ym)
        .then(function (data) {
          renderCalendar(data.dates || []);
        })
        .catch(function () {
          calGrid.innerHTML = '<p class="kn-muted">Impossible de charger les disponibilités.</p>';
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
      var startOffset = (first.getDay() + 6) % 7;
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

    function timeOfDay(time) {
      var h = parseInt(time.split(":")[0], 10);
      if (h < 12) return "Matin";
      if (h < 18) return "Après-midi";
      return "Soir";
    }

    function selectDate(dateStr, btnEl) {
      calGrid.querySelectorAll(".kn-resched-cal-day").forEach(function (b) {
        b.classList.remove("is-selected");
      });
      btnEl.classList.add("is-selected");

      slotList.innerHTML = '<p class="kn-muted">Chargement…</p>';
      fetchJson(baseUrl() + "/creneaux?job_id=" + encodeURIComponent(jobId) + "&date=" + dateStr)
        .then(function (data) {
          renderSlots(dateStr, data.slots || {});
        })
        .catch(function () {
          slotList.innerHTML = '<p class="kn-muted">Impossible de charger les créneaux.</p>';
        });
    }

    function renderSlots(dateStr, slots) {
      slotList.innerHTML = "";
      var times = Object.keys(slots).sort();
      var buckets = { "Matin": [], "Après-midi": [], "Soir": [] };
      times.forEach(function (time) {
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
          shown++;
          var row = document.createElement("button");
          row.type = "button";
          row.className = "kn-resched-slot";
          row.textContent = time;
          row.addEventListener("click", function () {
            dateInput.value = dateStr;
            timeInput.value = time;
            form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
          });
          slotList.appendChild(row);
        });
      });

      if (shown === 0) {
        slotList.innerHTML = '<p class="kn-muted">Aucun créneau libre ce jour-là.</p>';
      }
    }
  }

  document.querySelectorAll(".kn-manage-open-resched").forEach(function (btn) {
    var jobId = btn.getAttribute("data-job-id");
    var panel = document.querySelector('.kn-resched-panel[data-job-id="' + jobId + '"]');
    if (panel) initPanel(panel, btn);
  });
})();
