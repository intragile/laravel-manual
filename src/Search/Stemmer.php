<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Search;

interface Stemmer
{
    /**
     * @param  string  $normalizedWord  lowercase ASCII (accents already folded)
     */
    public function stem(string $normalizedWord): string;
}
