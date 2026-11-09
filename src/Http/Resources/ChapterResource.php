<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Manual\Content\ManualRepository;

/**
 * One rendered chapter.
 *
 * @phpstan-import-type Chapter from ManualRepository
 *
 * @property Chapter $resource
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class ChapterResource extends JsonResource
{
    /**
     * Pinned so a host-wide JsonResource::withoutWrapping() cannot change the documented shape.
     *
     * @var string|null
     */
    public static $wrap = 'data';

    /**
     * The per-item @var tags are read by OpenAPI generators such as Scramble, which cannot infer
     * these types through the repository; they mirror the @return shape.
     *
     * @return array{
     *     slug: string,
     *     number: string,
     *     title: string,
     *     summary: string,
     *     section: string,
     *     position: int,
     *     total: int,
     *     readingMinutes: int,
     *     html: string,
     *     headings: list<array{anchor: string, text: string, level: int}>,
     *     prev: array{slug: string, title: string}|null,
     *     next: array{slug: string, title: string}|null,
     *     locale: string,
     *     fallback: bool,
     * }
     */
    public function toArray(Request $request): array
    {
        $chapter = $this->resource;

        $headings = [];
        foreach ($chapter['headings'] as $heading) {
            $headings[] = ['anchor' => $heading['anchor'], 'text' => $heading['text'], 'level' => $heading['level']];
        }

        return [
            /** @var string */
            'slug' => $chapter['slug'],
            /** @var string */
            'number' => $chapter['number'],
            /** @var string */
            'title' => $chapter['title'],
            /** @var string */
            'summary' => $chapter['summary'],
            /** @var string */
            'section' => $chapter['section'],
            /** @var int */
            'position' => $chapter['position'],
            /** @var int */
            'total' => $chapter['total'],
            /** @var int */
            'readingMinutes' => $chapter['readingMinutes'],
            /** @var string */
            'html' => $chapter['html'],
            /** @var list<array{anchor: string, text: string, level: int}> */
            'headings' => $headings,
            /** @var array{slug: string, title: string}|null */
            'prev' => $chapter['prev'],
            /** @var array{slug: string, title: string}|null */
            'next' => $chapter['next'],
            /** @var string */
            'locale' => $chapter['locale'],
            /** @var bool */
            'fallback' => $chapter['fallback'],
        ];
    }
}
