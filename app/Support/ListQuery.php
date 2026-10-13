<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Parses the shared list parameters (contract §1.8): page, per_page (clamped), search,
 * sort (`field` / `-field`, allow-listed) and PHP-style array filters.
 */
final class ListQuery
{
    public const MAX_PER_PAGE = 100;

    public function __construct(private readonly Request $request) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    public function perPage(int $default = 25): int
    {
        $value = (int) $this->request->query('per_page', (string) $default);

        return max(1, min(self::MAX_PER_PAGE, $value ?: $default));
    }

    public function search(): ?string
    {
        $term = trim((string) $this->request->query('search', ''));

        return $term === '' ? null : mb_substr($term, 0, 100);
    }

    /**
     * Normalises a filter that may arrive as `status=a`, `status[]=a&status[]=b` or `status=a,b`,
     * keeping only allowed values.
     *
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    public function filter(string $key, ?array $allowed = null): array
    {
        $raw = $this->request->query($key);

        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $values = array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $values,
        ), fn ($v) => $v !== '')));

        return $allowed === null ? $values : array_values(array_intersect($values, $allowed));
    }

    public function bool(string $key): ?bool
    {
        if (! $this->request->has($key)) {
            return null;
        }

        return filter_var($this->request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function date(string $key): ?string
    {
        $value = (string) $this->request->query($key, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }

    /**
     * Applies an allow-listed sort. `$columns` maps public sort keys to columns (or closures).
     *
     * @param  array<string, string|callable>  $columns
     */
    public function applySort(Builder $query, array $columns, string $default): Builder
    {
        $sort = (string) $this->request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! array_key_exists($key, $columns)) {
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
            $key = ltrim($default, '-');
        }

        $column = $columns[$key];

        if (is_callable($column)) {
            $column($query, $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        // Deterministic paging.
        return $query->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    public function paginate(Builder|Relation $query, int $defaultPerPage = 25): LengthAwarePaginator
    {
        return $query->paginate($this->perPage($defaultPerPage))->withQueryString();
    }
}
