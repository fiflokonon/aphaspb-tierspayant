<?php

namespace App\Support;

/**
 * Un numéro WhatsApp, ramené au format international E.164.
 *
 * Saisi à la main par une officine, il arrive avec des espaces, des points,
 * un « 00 » ou sans indicatif : on le range sous une seule forme pour que le
 * lien wa.me fonctionne à tous les coups. Sans indicatif, le Bénin (+229).
 */
class WhatsappNumber
{
    protected const DEFAULT_COUNTRY = '229';

    /**
     * Le numéro au format `+` suivi de 8 à 15 chiffres, ou null s'il n'y ressemble pas.
     */
    public static function normalize(string $input): ?string
    {
        $compact = preg_replace('/[\s.\-()]/u', '', $input) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        } elseif (! str_starts_with($compact, '+')) {
            $compact = '+'.self::DEFAULT_COUNTRY.$compact;
        }

        return preg_match('/^\+\d{8,15}$/', $compact) === 1 ? $compact : null;
    }

    /**
     * Un lien qui ouvre la conversation, message déjà tapé.
     *
     * rawurlencode() et non urlencode() : WhatsApp lirait les « + » d'un
     * encodage de formulaire comme des plus, pas comme des espaces.
     */
    public static function link(string $e164, string $message): string
    {
        return 'https://wa.me/'.ltrim($e164, '+').'?text='.rawurlencode($message);
    }
}
