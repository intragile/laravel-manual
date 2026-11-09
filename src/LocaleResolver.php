<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * Resolves the locale a request ASKS for. It is deliberately not limited to the
 * allowlist: the repository applies the allowlist and the fallback chain and
 * reports `fallback: true` when it serves another language.
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class LocaleResolver
{
    private const PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

    public function __construct(private readonly Config $config) {}

    /**
     * The first syntactically valid candidate of: ?locale, the Accept-Language tags in order,
     * the application locale. An allowed candidate is returned in its configured spelling
     * (`pt_BR` for `pt-br`); a region tag whose full form is not allowed is offered as its
     * primary subtag (`hu-HU` => `hu`). Nothing valid at all: the source locale.
     */
    public function resolve(Request $request): string
    {
        $query = $request->query('locale');

        if (is_string($query) && ($locale = $this->candidate($query)) !== null) {
            return $locale;
        }

        foreach ($request->getLanguages() as $language) {
            if (($locale = $this->candidate($language)) !== null) {
                return $locale;
            }
        }

        return $this->candidate(app()->getLocale()) ?? $this->sourceLocale();
    }

    private function candidate(string $tag): ?string
    {
        $full = strtolower(str_replace('_', '-', trim($tag)));
        $primary = explode('-', $full)[0];
        $allowed = $this->allowed();

        if (preg_match(self::PATTERN, $full) === 1) {
            return $allowed[$full] ?? $allowed[$primary] ?? $primary;
        }

        // e.g. zh-hant-tw: the full tag is not a supported shape, its primary subtag may be.
        if ($primary !== $full && preg_match(self::PATTERN, $primary) === 1) {
            return $allowed[$primary] ?? $primary;
        }

        return null;
    }

    /**
     * Normalised tag (lower case, "-") => the locale as configured; `manual.locales` plus `source_locale`.
     *
     * @return array<string, string>
     */
    private function allowed(): array
    {
        $locales = $this->config->get('manual.locales', []);
        $locales = is_array($locales) ? array_filter($locales, is_string(...)) : [];
        $locales[] = $this->sourceLocale();

        $normalised = [];
        foreach ($locales as $locale) {
            $normalised[strtolower(str_replace('_', '-', $locale))] ??= $locale;
        }

        return $normalised;
    }

    private function sourceLocale(): string
    {
        $source = $this->config->get('manual.source_locale', 'en');

        return is_string($source) ? $source : 'en';
    }
}
