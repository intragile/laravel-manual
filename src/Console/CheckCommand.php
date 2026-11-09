<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Console;

use Illuminate\Console\Command;
use IntrAgile\Manual\Validation\ManualValidator;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal The manual:check command, its options and exit codes are public API; this class is not.
 */
final class CheckCommand extends Command
{
    protected $name = 'manual:check';

    protected $description = 'Validate the manual: tree, translations, structure parity, markup and links';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check only the source locale and this locale (repeatable); it must be configured');
    }

    public function handle(ManualValidator $validator): int
    {
        $configured = $validator->configuredLocales();
        $source = $configured[0];

        $requested = array_values(array_unique(array_filter(
            array_map(strval(...), (array) $this->option('locale')),
            static fn (string $locale): bool => $locale !== '',
        )));

        $errors = [];

        foreach ($requested as $locale) {
            if (! in_array($locale, $configured, true)) {
                $errors[] = sprintf('Locale "%s" is not configured (the source locale is "%s"; others must be listed in manual.locales).', $locale, $source);
            }
        }

        $errors = [...$errors, ...$validator->errors($requested === [] ? null : $requested)];

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            $this->error(sprintf('Manual check failed with %d error%s.', count($errors), count($errors) === 1 ? '' : 's'));

            return self::FAILURE;
        }

        $this->info('Manual OK');

        return self::SUCCESS;
    }
}
