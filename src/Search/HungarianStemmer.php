<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

/**
 * Light suffix trimming: a suffix is only cut when a stem of at least four
 * characters remains. Not a full stemmer; the substring search covers the rest.
 */
final class HungarianStemmer implements Stemmer
{
    /** Longest first where one suffix contains another. */
    private const SUFFIXES = [
        'eket', 'okat', 'akat', 'oket', 'ban', 'ben', 'bol', 'hoz', 'hez', 'nak', 'nek',
        'val', 'vel', 'rol', 'ra', 're', 'ok', 'ek', 'ak', 'ot', 'et', 'at',
    ];

    public function stem(string $normalizedWord): string
    {
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($normalizedWord, $suffix) && mb_strlen($normalizedWord) - mb_strlen($suffix) >= 4) {
                return substr($normalizedWord, 0, -strlen($suffix));
            }
        }

        return $normalizedWord;
    }
}
