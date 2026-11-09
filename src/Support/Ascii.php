<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Support;

/**
 * Pure-PHP accent folding. Deliberately not iconv `ASCII//TRANSLIT`, whose
 * output differs between macOS and glibc.
 *
 * @internal
 */
final class Ascii
{
    /** @var array<string, string>|null */
    private static ?array $accentMap = null;

    /**
     * Folds known accented letters to their lowercase ASCII base; everything
     * else is left untouched.
     */
    public static function fold(string $text): string
    {
        $folded = strtr($text, self::map());

        // Decomposed (NFD) accents: drop combining marks.
        return preg_replace('/\p{Mn}+/u', '', $folded) ?? $folded;
    }

    /**
     * Single-character variant of fold() (a map lookup, no strtr per call).
     * A combining mark folds to the empty string.
     */
    public static function foldChar(string $char): string
    {
        if (strlen($char) === 1) {
            return $char;
        }

        $map = self::map();

        if (isset($map[$char])) {
            return $map[$char];
        }

        return preg_match('/^\p{Mn}+$/u', $char) === 1 ? '' : $char;
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$accentMap === null) {
            $map = [];
            $groups = [
                'a' => 'áàâäãåāăąÁÀÂÄÃÅĀĂĄ', 'c' => 'çćĉčÇĆĈČ', 'd' => 'ďđĎĐ', 'e' => 'éèêëēĕėęěÉÈÊËĒĔĖĘĚ',
                'g' => 'ĝğģĜĞĢ', 'h' => 'ĥħĤĦ', 'i' => 'íìîïĩīĭįıÍÌÎÏĨĪĬĮİ', 'j' => 'ĵĴ', 'k' => 'ķĶ',
                'l' => 'ĺļľłĹĻĽŁ', 'n' => 'ñńņňÑŃŅŇ', 'o' => 'óòôöõøōŏőÓÒÔÖÕØŌŎŐ', 'r' => 'ŕŗřŔŖŘ',
                's' => 'śŝşšșŚŜŞŠȘ', 't' => 'ţťŧțŢŤŦȚ', 'u' => 'úùûüũūŭůűųÚÙÛÜŨŪŬŮŰŲ', 'w' => 'ŵŴ',
                'y' => 'ýÿŷÝŸŶ', 'z' => 'źżžŹŻŽ',
            ];
            foreach ($groups as $base => $chars) {
                foreach (mb_str_split($chars) as $char) {
                    $map[$char] = $base;
                }
            }
            $map['ß'] = 'ss';
            $map['æ'] = 'ae';
            $map['Æ'] = 'ae';
            $map['œ'] = 'oe';
            $map['Œ'] = 'oe';
            self::$accentMap = $map;
        }

        return self::$accentMap;
    }
}
