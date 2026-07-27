<?php

declare(strict_types=1);

namespace Keepnew\Support;

use Keepnew\Core\Database;

/**
 * Textes fixes des 9 étapes du tunnel public (public/widget.js), éditables
 * depuis /admin/formulaire/textes. Stockage : un seul enregistrement JSON
 * (`settings.key = 'widget.tunnel_texts'`) fusionné sur ces valeurs par
 * défaut — une clé absente du JSON stocké (nouveau texte ajouté au code
 * depuis, ou jamais personnalisé) retombe toujours sur le texte d'origine.
 */
final class TunnelTexts
{
    /** @var array<string, array<string, string>> */
    public const DEFAULTS = [
        'where' => [
            'title' => 'Où souhaitez-vous être nettoyé ?',
            'onsite_title' => "Je veux qu'on vienne chez moi",
            'onsite_subtitle' => 'Un technicien se déplace à votre adresse.',
            'workshop_title' => "Je viens à l'atelier",
            'workshop_subtitle' => 'Vous déposez, nous nettoyons. Souvent moins cher.',
            'postal_label' => 'Votre code postal',
            'postal_placeholder' => 'Ex. 4000',
            'continue_button' => 'Continuer',
            'zone_reassurance' => 'Oui, nous intervenons à Liège et dans un rayon de 25 km.',
            'postal_required_error' => "Indiquez d'abord votre code postal.",
            'zone_check_error' => 'Impossible de vérifier votre zone pour le moment.',
        ],
        'what' => [
            'title' => 'Quelle prestation ?',
            'back_link' => '← Revenir',
        ],
        'details' => [
            'title' => 'Configurez votre prestation',
            'variants_heading' => 'Votre modèle',
            'extras_heading' => 'Options',
            'price_reassurance' => 'Prix ferme. Aucun supplément le jour de l\'intervention.',
            'add_to_cart_button' => 'Ajouter au panier',
            'price_duration_label' => 'TVAC · durée estimée',
            'unavailable_mode_error' => 'Indisponible dans ce mode.',
            'add_to_cart_error' => 'Impossible d\'ajouter cette prestation au panier.',
        ],
        'cart' => [
            'title' => 'Votre devis',
            'empty_message' => 'Votre panier est vide. Ajoutez une prestation pour commencer.',
            'empty_button' => 'Choisir une prestation',
            'onsite_badge' => 'À domicile',
            'workshop_badge' => 'Atelier',
            'discount_label' => 'Remise groupée',
            'total_label' => 'Total TVAC',
            'add_another_button' => 'Ajouter une autre prestation',
            'checkout_button' => 'Finaliser ma réservation',
        ],
        'intake' => [
            'title' => 'Quelques précisions',
            'reassurance' => "Si l'état diffère, on vous prévient avant de commencer. Vous restez libre de refuser.",
            'continue_button' => 'Continuer',
            'validation_error' => 'Merci de compléter les champs requis.',
        ],
        'contact' => [
            'title' => 'Vos coordonnées',
            'first_name_label' => 'Prénom',
            'last_name_label' => 'Nom',
            'email_label' => 'Email',
            'phone_label' => 'Téléphone',
            'address_heading' => "Adresse d'intervention",
            'street_label' => 'Rue',
            'number_label' => 'Numéro',
            'postal_label' => 'Code postal',
            'city_label' => 'Ville',
            'privacy_reassurance' => 'Vos données servent uniquement à organiser votre rendez-vous. Conservées le temps légal. Voir notre politique de confidentialité.',
            'consent_label' => "J'accepte les conditions générales et la politique de confidentialité.",
            'continue_button' => 'Choisir un créneau',
            'required_error' => "Merci d'indiquer au moins votre prénom et votre email.",
            'consent_error' => 'Merci d\'accepter les conditions pour continuer.',
        ],
        'slot' => [
            'title' => 'Choisissez votre créneau',
            'empty_onsite' => 'Aucun créneau à domicile disponible sur la période.',
            'empty_workshop' => 'Aucun créneau atelier disponible sur la période.',
            'onsite_heading' => 'À domicile',
            'workshop_heading' => "À l'atelier",
            'sms_reassurance' => 'Vous recevez un SMS quand le technicien part vers chez vous.',
            'continue_button' => 'Continuer',
            'select_required_error' => 'Merci de choisir un créneau.',
            'load_error' => 'Impossible de vérifier les disponibilités pour le moment.',
            'retry_button' => 'Réessayer',
            'morning_label' => 'Matin',
            'afternoon_label' => 'Après-midi',
            'evening_label' => 'Soir',
            'workshop_slot_prefix' => 'Dépôt ',
            'workshop_slot_reprise' => ' · reprise ~',
        ],
        'recap' => [
            'title' => 'Récapitulatif',
            'discount_label' => 'Remise groupée',
            'total_label' => 'Total TVAC',
            'promo_label' => 'Code promo',
            'promo_button' => 'Appliquer le code',
            'cancellation_reassurance' => 'Annulation sans frais jusqu\'à 24 h avant. Vous payez après l\'intervention.',
            'confirm_button' => 'Confirmer la demande',
            'slot_taken_error' => "Ce créneau vient d'être réservé. Merci d'en choisir un autre.",
        ],
        'done' => [
            'title' => "C'est confirmé",
            'message_prefix' => 'Votre demande ',
            'message_suffix' => ' est bien enregistrée.',
            'subtext' => "Vous recevez un email de confirmation. Vous payez après l'intervention.",
            'contact_prefix' => 'Une question ? Appelez-nous au ',
            'contact_fallback_phone' => '+32 4 000 00 00',
            'restart_button' => 'Réserver une autre prestation',
        ],
        'shared' => [
            'loading' => 'Chargement…',
            'generic_error' => 'Une erreur est survenue.',
            'out_of_zone_message' => "Nous n'intervenons pas encore automatiquement à cette adresse. Contactez-nous pour un devis sur mesure :",
            'out_of_zone_fallback' => 'Contactez-nous depuis notre site.',
        ],
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public static function resolve(Database $db): array
    {
        $raw = $db->scalar("SELECT `value` FROM settings WHERE `key` = 'widget.tunnel_texts'");
        $stored = $raw !== null ? (json_decode((string) $raw, true) ?: []) : [];
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_replace_recursive(self::DEFAULTS, $stored);
    }
}
