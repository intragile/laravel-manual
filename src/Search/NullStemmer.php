<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

final class NullStemmer implements Stemmer
{
    public function stem(string $normalizedWord): string
    {
        return $normalizedWord;
    }
}
