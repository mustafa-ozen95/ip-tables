<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Liste ekranlarının metin araması — TEK kural, bütün modellerde.
 *
 * Aranan metindeki `%` ve `_` joker değil harftir: "10_0" yazan "10x0"
 * bulmamalı, tek bir "%" bütün tabloyu döndürmemeli. Kaçış karakteri `!`:
 * ters bölü MariaDB'de varsayılan, SQLite'ta tanımsız; `!` ikisinde de
 * aynı çalışır.
 */
trait Searchable
{
    /** @return list<string> aranan sütunlar */
    abstract protected function searchColumns(): array;

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $inner) use ($like): void {
            foreach ($this->searchColumns() as $column) {
                $inner->orWhereRaw($inner->getQuery()->getGrammar()->wrap($column)." like ? escape '!'", [$like]);
            }
        });
    }
}
