<?php

declare(strict_types=1);

namespace IntrAgile\Manual;

use IntrAgile\Manual\Content\ManualRepository;
use IntrAgile\Manual\Search\ManualSearch;

/**
 * The root of the Manual facade: one object for the tree, chapters, raw markdown and search.
 *
 * Not final, so Manual::spy() / Manual::partialMock() and test doubles can replace it.
 *
 * @phpstan-import-type TreeSection from ManualRepository
 * @phpstan-import-type Chapter from ManualRepository
 * @phpstan-import-type SearchHit from ManualSearch
 */
class ManualManager
{
    public function __construct(
        private readonly ManualRepository $repository,
        private readonly ManualSearch $search,
    ) {}

    /**
     * @return list<TreeSection>
     */
    public function tree(string $locale): array
    {
        return $this->repository->tree($locale);
    }

    /**
     * @return Chapter|null
     */
    public function chapter(string $slug, string $locale): ?array
    {
        return $this->repository->chapter($slug, $locale);
    }

    /**
     * @return array{markdown: string, locale: string}|null
     */
    public function markdown(string $slug, string $locale): ?array
    {
        return $this->repository->markdown($slug, $locale);
    }

    /**
     * @return list<SearchHit>
     */
    public function search(string $query, string $locale, ?int $limit = null): array
    {
        return $this->search->search($query, $locale, $limit);
    }
}
