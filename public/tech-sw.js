/* Keepnew — service worker de l'app technicien (PWA).
 * Cache la coquille statique pour un démarrage rapide et un mode dégradé hors
 * ligne. Les données (planning, jobs) restent réseau-first : on ne sert pas de
 * données périmées sur le terrain. */
"use strict";

const CACHE = "kn-tech-v1";
const SHELL = ["/assets/design-tokens.css", "/assets/admin.css", "/tech.webmanifest"];

self.addEventListener("install", function (event) {
  event.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }));
  self.skipWaiting();
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    })
  );
  self.clients.claim();
});

self.addEventListener("fetch", function (event) {
  const url = new URL(event.request.url);
  // Coquille statique : cache-first.
  if (SHELL.indexOf(url.pathname) >= 0) {
    event.respondWith(caches.match(event.request).then(function (r) { return r || fetch(event.request); }));
    return;
  }
  // Pages/données : réseau d'abord, repli cache si hors ligne.
  if (event.request.method === "GET") {
    event.respondWith(
      fetch(event.request).catch(function () { return caches.match(event.request); })
    );
  }
});
