<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests\Feature;

use IntrAgile\Manual\ManualServiceProvider;
use IntrAgile\Manual\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertSame('authenticated', config('manual.access'));
        $this->assertSame('en', config('manual.source_locale'));
        $this->assertSame(['tip', 'warning', 'note', 'steps'], config('manual.callouts'));
        $this->assertSame(3, config('manual.search.min_term_length'));
        $this->assertSame(10, config('manual.search.limit'));
        $this->assertSame('manual', config('manual.routes.prefix'));
        $this->assertSame('manual', config('manual.cache.prefix'));
    }

    public function test_config_is_publishable(): void
    {
        $paths = ManualServiceProvider::pathsToPublish(ManualServiceProvider::class, 'manual-config');

        $this->assertCount(1, $paths);
        $this->assertSame(config_path('manual.php'), array_values($paths)[0]);
        $this->assertFileExists((string) array_key_first($paths));
    }
}
