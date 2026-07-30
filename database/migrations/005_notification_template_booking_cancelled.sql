-- Migration 005 — Modèle d'e-mail pour l'événement « Réservation annulée ».
--
-- L'événement `booking_cancelled` existe depuis le seed initial, mais aucun
-- modèle de message n'y était rattaché : NotificationService::trigger() sort
-- immédiatement quand un événement n'a aucun template actif, donc aucune
-- annulation n'a jamais été notifiée. Le back-office (/admin/notifications)
-- ne sait qu'ÉDITER des modèles existants, jamais en créer : sans cette
-- insertion, l'événement reste muet et invisible dans l'interface.
--
-- Après exécution, le texte est modifiable dans /admin/notifications.
--
-- À exécuter sur la base de production (OVH) : le projet n'a pas de runner de
-- migration. Idempotente : ne fait rien si un modèle e-mail existe déjà.

INSERT INTO notification_templates (event_id, channel, offset_minutes, subject, body, is_active)
SELECT e.id, 'email', 0,
       'Votre rendez-vous Keepnew {{booking.reference}} est annulé',
       CONCAT(
           'Bonjour {{customer.first_name}}, votre rendez-vous du {{job.date}} ',
           '({{booking.reference}}) est bien annulé. Rien ne vous sera facturé. ',
           'Au plaisir de vous revoir : réservez quand vous le souhaitez sur keepnew.be.'
       ),
       1
  FROM notification_events e
 WHERE e.event_key = 'booking_cancelled'
   AND NOT EXISTS (
         SELECT 1 FROM notification_templates t
          WHERE t.event_id = e.id AND t.channel = 'email'
       );
