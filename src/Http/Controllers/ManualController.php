<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use IntrAgile\Manual\Content\ContentTree;
use IntrAgile\Manual\Http\Resources\ChapterResource;
use IntrAgile\Manual\Http\Resources\SearchResultResource;
use IntrAgile\Manual\Http\Resources\SectionResource;
use IntrAgile\Manual\LocaleResolver;
use IntrAgile\Manual\ManualManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The resolved client locale is passed to the repository, which applies the allowlist and
 * the fallback chain and reports `fallback`.
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class ManualController extends Controller
{
    public function __construct(
        private readonly ManualManager $manual,
        private readonly ContentTree $tree,
        private readonly LocaleResolver $locales,
    ) {}

    /**
     * The manual tree: sections in order, each with its chapters.
     *
     * `locale` is the language the tree is served in (the requested one when it is configured,
     * otherwise the first configured `fallback` language, otherwise the source locale); `fallback` is true when that differs from the requested one.
     * A single chapter missing from that language is still listed from the next language of the
     * fallback chain; its own `locale`/`fallback` are returned by the chapter endpoint.
     *
     * @response array{
     *     data: list<array{id: string, icon: string, title: string, chapters: list<array{slug: string, number: string, title: string, summary: string}>}>,
     *     locale: string,
     *     fallback: bool,
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'locale' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $requested = $this->locales->resolve($request);
        $served = $this->tree->resolveLocale($requested);

        return SectionResource::collection($this->manual->tree($requested))->additional([
            'locale' => $served,
            'fallback' => $served !== $requested,
        ]);
    }

    /**
     * One rendered chapter by slug (`section/chapter`).
     *
     * `data.locale` is the language of the file actually served; `data.fallback` is true when that
     * differs from the requested language.
     */
    public function chapter(Request $request): ChapterResource
    {
        /** @var array{slug: string, locale?: string} $validated */
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:200'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $chapter = $this->manual->chapter($validated['slug'], $this->locales->resolve($request));

        if ($chapter === null) {
            throw new NotFoundHttpException;
        }

        return new ChapterResource($chapter);
    }

    /**
     * Full-text search; returns matching chapters with a short plain-text snippet.
     *
     * `locale` and `fallback` follow the same rules as the tree.
     *
     * @response array{
     *     data: list<array{slug: string, section: string, title: string, snippet: string, score: int}>,
     *     locale: string,
     *     fallback: bool,
     * }
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        /** @var array{q: string, locale?: string} $validated */
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $requested = $this->locales->resolve($request);
        $served = $this->tree->resolveLocale($requested);

        return SearchResultResource::collection($this->manual->search($validated['q'], $requested))->additional([
            'locale' => $served,
            'fallback' => $served !== $requested,
        ]);
    }
}
