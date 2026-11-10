<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * Resolves the locale a request asks for: an allowed client locale when there is one,
 * otherwise the client's first valid (unallowed) language, which the repository serves
 * through the fallback chain with `fallback: true`.
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class LocaleResolver
{
    private const PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

    public function __construct(private readonly Config $config) {}

    /**
     * Client candidates, in order: ?locale, then each Accept-Language tag in q order (full tag,
     * then primary subtag); tags with q=0 are dropped.
     *
     * 1. The first client candidate in `manual.locales` + `source_locale`, in its configured
     *    spelling (`pt-br` => `pt_BR`).
     * 2. Else, when the client sent any syntactically valid candidate, the primary subtag of the
     *    first one (lower case). It is deliberately not allowlisted: the repository serves the
     *    fallback chain and reports `fallback: true`.
     * 3. Else (nothing usable from the client) the application locale when it is allowed,
     *    otherwise the source locale. The app locale never competes with a client request, so an
     *    app locale equal to the source cannot hide a fallback.
     */
    public function resolve(Request $request): string
    {
        $allowed = $this->allowed();

        $tags = [];
        $query = $request->query('locale');
        if (is_string($query)) {
            $tags[] = $query;
        }
        foreach (AcceptHeader::fromString($request->headers->get('Accept-Language'))->all() as $item) {
            // q=0 means "not acceptable"; Request::getLanguages() would keep it.
            if ($item->getQuality() > 0) {
                $tags[] = $item->getValue();
            }
        }

        $firstValid = null;

        foreach ($tags as $tag) {
            $full = $this->normalise($tag);
            $primary = explode('-', $full)[0];

            foreach ([$full, $primary] as $candidate) {
                if (isset($allowed[$candidate])) {
                    return $allowed[$candidate];
                }
            }

            if ($firstValid === null && (preg_match(self::PATTERN, $full) === 1 || preg_match(self::PATTERN, $primary) === 1)) {
                $firstValid = $primary;
            }
        }

        if ($firstValid !== null) {
            return $firstValid;
        }

        // Application::getLocale() reads `app.locale`, and setLocale() writes it.
        $appLocale = $this->config->get('app.locale');

        if (is_string($appLocale) && isset($allowed[$this->normalise($appLocale)])) {
            return $allowed[$this->normalise($appLocale)];
        }

        return $this->sourceLocale();
    }

    private function normalise(string $tag): string
    {
        return strtolower(str_replace('_', '-', trim($tag)));
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
            $normalised[$this->normalise($locale)] ??= $locale;
        }

        return $normalised;
    }

    private function sourceLocale(): string
    {
        $source = $this->config->get('manual.source_locale', 'en');

        return is_string($source) ? $source : 'en';
    }
}
