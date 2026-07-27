(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    var el = document.getElementById("kn-schedule-map");
    if (!el || !window.L) return;
    var jobs = [];
    try {
      jobs = JSON.parse(el.getAttribute("data-jobs") || "[]");
    } catch (e) {
      return;
    }
    if (jobs.length === 0) return;

    var map = window.L.map(el);
    window.L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: "&copy; OpenStreetMap",
      maxZoom: 19,
    }).addTo(map);

    var markers = jobs.map(function (j) {
      return window.L.marker([j.lat, j.lng]).bindPopup(j.label).addTo(map);
    });

    if (markers.length === 1) {
      map.setView([jobs[0].lat, jobs[0].lng], 14);
    } else {
      map.fitBounds(window.L.featureGroup(markers).getBounds(), { padding: [24, 24] });
    }
  });
})();
