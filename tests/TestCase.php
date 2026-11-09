<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Tests;

use IntrAgile\Manual\ManualServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ManualServiceProvider::class];
    }
}
