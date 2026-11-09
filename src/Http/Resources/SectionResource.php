<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Manual\Content\ManualRepository;

/**
 * One section of the manual tree with its chapter list.
 *
 * @phpstan-import-type TreeSection from ManualRepository
 *
 * @property TreeSection $resource
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class SectionResource extends JsonResource
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
     *     id: string,
     *     icon: string,
     *     title: string,
     *     chapters: list<array{slug: string, number: string, title: string, summary: string}>,
     * }
     */
    public function toArray(Request $request): array
    {
        $chapters = [];
        foreach ($this->resource['chapters'] as $chapter) {
            $chapters[] = [
                'slug' => $chapter['slug'],
                'number' => $chapter['number'],
                'title' => $chapter['title'],
                'summary' => $chapter['summary'],
            ];
        }

        return [
            /** @var string */
            'id' => $this->resource['id'],
            /** @var string */
            'icon' => $this->resource['icon'],
            /** @var string */
            'title' => $this->resource['title'],
            /** @var list<array{slug: string, number: string, title: string, summary: string}> */
            'chapters' => $chapters,
        ];
    }
}
