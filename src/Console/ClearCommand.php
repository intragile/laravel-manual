<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Console;

use Illuminate\Console\Command;
use IntrAgile\Manual\Content\ManualRepository;

/**
 * @internal The manual:clear command is public API; this class is not.
 */
final class ClearCommand extends Command
{
    protected $signature = 'manual:clear';

    protected $description = 'Invalidate the cached manual tree, chapters and search index (any cache store)';

    public function handle(ManualRepository $repository): int
    {
        $generation = $repository->flushCache();

        $this->info(sprintf('Manual cache cleared (generation %d).', $generation));

        return self::SUCCESS;
    }
}
