<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

/**
 * Light suffix trimming (`ies`, `es`, `s`, `ing`, `ed`, `y`), only when a stem of
 * at least four characters remains. `ies` and `y` both reduce to the bare stem
 * (queries and query become `quer`), so with the unstemmed substring index every
 * singular/plural combination of query and document matches.
 */
final class EnglishStemmer implements Stemmer
{
    public function stem(string $normalizedWord): string
    {
        foreach (['ies', 'es', 's', 'ing', 'ed', 'y'] as $suffix) {
            if (str_ends_with($normalizedWord, $suffix) && strlen($normalizedWord) - strlen($suffix) >= 4) {
                return substr($normalizedWord, 0, -strlen($suffix));
            }
        }

        return $normalizedWord;
    }
}
