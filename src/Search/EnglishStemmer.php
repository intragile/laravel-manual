<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

/**
 * Light suffix trimming (`ies` becomes `y`, then `es`, `s`, `ing`, `ed`), only
 * when a stem of at least four characters remains.
 */
final class EnglishStemmer implements Stemmer
{
    public function stem(string $normalizedWord): string
    {
        foreach (['ies', 'es', 's', 'ing', 'ed'] as $suffix) {
            if (str_ends_with($normalizedWord, $suffix) && strlen($normalizedWord) - strlen($suffix) >= 4) {
                $stem = substr($normalizedWord, 0, -strlen($suffix));

                return $suffix === 'ies' ? $stem.'y' : $stem;
            }
        }

        return $normalizedWord;
    }
}
