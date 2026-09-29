<?php

namespace App\Support;

/**
 * Un numéro WhatsApp, ramené au format international E.164.
 *
 * Saisi à la main par une officine, il arrive avec des espaces, des points,
 * un « 00 », avec ou sans indicatif : on le range sous une seule forme pour
 * que le lien wa.me fonctionne à tous les coups.
 *
 * Le Bénin est le cas par défaut, et son plan a changé fin 2024 : un mobile
 * compte désormais 10 chiffres et commence par 01. Un ancien numéro à 8
 * chiffres reçoit donc son 01 — sans lui, wa.me ouvrirait une conversation
 * avec personne. Un numéro étranger doit porter son indicatif (+ ou 00).
 */
class WhatsappNumber
{
    protected const BENIN = '229';

    /**
     * Le numéro au format `+` suivi de 8 à 15 chiffres, ou null s'il n'y ressemble pas.
     */
    public static function normalize(string $input): ?string
    {
        $compact = preg_replace('/[\s.\-()]/u', '', $input) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (str_starts_with($compact, '+')) {
            $international = substr($compact, 1);

            // Un numéro béninois saisi avec son indicatif suit le même plan.
            if (str_starts_with($international, self::BENIN)) {
                $national = self::beninNational(substr($international, strlen(self::BENIN)));

                return $national === null ? null : '+'.self::BENIN.$national;
            }

            return preg_match('/^\d{8,15}$/', $international) === 1 ? $compact : null;
        }

        // Sans + ni 00 : un numéro national, ou l'indicatif 229 tapé sans +.
        if (preg_match('/^229(\d{8}|01\d{8})$/', $compact) === 1) {
            $compact = substr($compact, strlen(self::BENIN));
        }

        $national = self::beninNational($compact);

        return $national === null ? null : '+'.self::BENIN.$national;
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

    /**
     * Le numéro tel qu'on le lit à voix haute : un béninois par paires
     * (+229 01 97 00 00 00), un étranger tel qu'il est rangé.
     */
    public static function display(string $e164): string
    {
        $prefix = '+'.self::BENIN;

        if (! str_starts_with($e164, $prefix)) {
            return $e164;
        }

        return $prefix.' '.implode(' ', str_split(substr($e164, strlen($prefix)), 2));
    }

    /**
     * Les 10 chiffres d'un mobile béninois (01 + 8), ou null.
     */
    protected static function beninNational(string $digits): ?string
    {
        if (preg_match('/^01\d{8}$/', $digits) === 1) {
            return $digits;
        }

        return preg_match('/^\d{8}$/', $digits) === 1 ? '01'.$digits : null;
    }
}
