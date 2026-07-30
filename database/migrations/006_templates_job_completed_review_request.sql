-- Migration 006 — Modèles « Intervention terminée » et « Demande d'avis »,
-- + réglage du lien public de dépôt d'avis.
--
-- Même situation que la migration 005 : les événements `job_completed` et
-- `review_request` existent depuis le seed initial sans aucun modèle rattaché.
-- NotificationService::trigger() sort immédiatement dans ce cas, et
-- /admin/notifications ne sait qu'ÉDITER des modèles existants, jamais en
-- créer : les deux événements étaient muets ET invisibles dans l'interface.
--
--   - `job_completed`  est déclenché automatiquement quand le technicien
--     marque l'intervention terminée depuis l'app terrain.
--   - `review_request` est déclenché manuellement par le bouton « Envoyer une
--     demande d'avis », qui n'apparaît que sur un rendez-vous terminé.
--
-- Après exécution, les deux textes sont modifiables dans /admin/notifications,
-- et le lien d'avis se règle dans /admin/reglages.
--
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de
-- migration. Idempotente : chaque insertion est conditionnée à l'absence d'un
-- modèle e-mail pour l'événement concerné.

-- 1. Réglage du lien de dépôt d'avis (vide : à renseigner dans /admin/reglages).
INSERT INTO settings (`key`, `value`, value_type, `group`, label, is_secret)
SELECT 'company.review_url', '', 'string', 'general',
       'Lien public de dépôt d''avis ({{review.url}})', 0
 WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'company.review_url');

-- 2. Intervention terminée.
INSERT INTO notification_templates (event_id, channel, offset_minutes, subject, body, is_active)
SELECT e.id, 'email', 0,
       'C''est terminé — merci {{customer.first_name}} !',
       CONCAT(
           'Bonjour {{customer.first_name}}, votre intervention est terminée. ',
           'Nous espérons que le résultat vous plaît ! Votre facture ',
           '({{booking.reference}}, {{booking.total}}) vous parvient séparément. ',
           'La moindre question, écrivez-nous : on répond vite.'
       ),
       1
  FROM notification_events e
 WHERE e.event_key = 'job_completed'
   AND NOT EXISTS (
         SELECT 1 FROM notification_templates t
          WHERE t.event_id = e.id AND t.channel = 'email'
       );

-- 3. Demande d'avis (déclenchée à la main depuis la fiche du rendez-vous).
INSERT INTO notification_templates (event_id, channel, offset_minutes, subject, body, is_active)
SELECT e.id, 'email', 0,
       'Votre avis compte pour nous, {{customer.first_name}}',
       CONCAT(
           'Bonjour {{customer.first_name}}, merci de nous avoir fait confiance. ',
           'Si le résultat vous a plu, un mot de votre part aide énormément une ',
           'petite équipe comme la nôtre : {{review.url}} — deux minutes suffisent. ',
           'Et si quelque chose n''allait pas, répondez à cet e-mail : on préfère ',
           'le savoir et le rattraper.'
       ),
       1
  FROM notification_events e
 WHERE e.event_key = 'review_request'
   AND NOT EXISTS (
         SELECT 1 FROM notification_templates t
          WHERE t.event_id = e.id AND t.channel = 'email'
       );
