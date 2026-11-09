<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Unit;

use IntrAgile\Manual\Search\EnglishStemmer;
use IntrAgile\Manual\Search\HungarianStemmer;
use IntrAgile\Manual\Search\NullStemmer;
use IntrAgile\Manual\Support\Ascii;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StemmerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function hungarian(): array
    {
        return [
            'eket' => ['tokeneket', 'token'],
            'nek is listed before ek' => ['tokenek', 'toke'],
            'ban' => ['rendszerban', 'rendszer'],
            'hoz' => ['webhookhoz', 'webhook'],
            'ot' => ['partnerot', 'partner'],
            'four left' => ['kutyak', 'kuty'],
            'exactly four left' => ['abcdek', 'abcd'],
            'three left' => ['abcek', 'abcek'],
            'ek' => ['rendszerek', 'rendszer'],
            'no suffix' => ['csatorna', 'csatorna'],
        ];
    }

    #[DataProvider('hungarian')]
    public function test_hungarian(string $word, string $expected): void
    {
        $this->assertSame($expected, (new HungarianStemmer)->stem($word));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function english(): array
    {
        return [
            'ies' => ['queries', 'query'],
            's' => ['tokens', 'token'],
            'es' => ['matches', 'match'],
            'ing' => ['listing', 'list'],
            'ed' => ['created', 'creat'],
            'too short' => ['uses', 'uses'],
            'no suffix' => ['token', 'token'],
        ];
    }

    #[DataProvider('english')]
    public function test_english(string $word, string $expected): void
    {
        $this->assertSame($expected, (new EnglishStemmer)->stem($word));
    }

    public function test_null_stemmer_returns_the_word(): void
    {
        $this->assertSame('tokenek', (new NullStemmer)->stem('tokenek'));
    }

    public function test_ascii_fold_is_pure_php(): void
    {
        $this->assertSame('arvizturo tukorfurogep', Ascii::fold('árvíztűrő tükörfúrógép'));
        $this->assertSame('strasse', Ascii::fold('straße'));
    }
}
