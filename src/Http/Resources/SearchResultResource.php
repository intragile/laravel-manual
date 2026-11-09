<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Manual\Search\ManualSearch;

/**
 * One search hit: the chapter and a plain-text snippet, never the full content.
 *
 * @phpstan-import-type SearchHit from ManualSearch
 *
 * @property SearchHit $resource
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class SearchResultResource extends JsonResource
{
    /**
     * Pinned so a host-wide JsonResource::withoutWrapping() cannot change the documented shape.
     *
     * @var string|null
     */
    public static $wrap = 'data';

    /**
     * The per-item @var tags are read by OpenAPI generators such as Scramble, which cannot infer
     * these types through the search service; they mirror the @return shape.
     *
     * @return array{slug: string, section: string, title: string, snippet: string, score: int}
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'slug' => $this->resource['slug'],
            /** @var string */
            'section' => $this->resource['section'],
            /** @var string */
            'title' => $this->resource['title'],
            /** @var string */
            'snippet' => $this->resource['snippet'],
            /** @var int */
            'score' => $this->resource['score'],
        ];
    }
}
