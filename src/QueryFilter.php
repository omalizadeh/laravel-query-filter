<?php

namespace Omalizadeh\QueryFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class QueryFilter
{
    protected Builder $builder;
    protected ModelFilter $modelFilter;

    public function __construct(Builder $builder, ModelFilter $modelFilter)
    {
        $this->builder = $builder;
        $this->modelFilter = $modelFilter;
    }

    public function applyFilter(): QueryFilterResult
    {
        if ($this->getFilter()->hasAnySelectedAttribute()) {
            $this->select();
        }

        if ($this->getFilter()->hasAnyFilterGroup()) {
            $this->applyFilterGroups();
        }

        $totalCount = $this->getBuilder()->count();

        if ($this->getFilter()->hasAnySum()) {
            $sums = $this->sum();
        }

        if ($this->getFilter()->hasAnySort()) {
            $this->sort();
        }

        if ($this->getFilter()->hasAnyRelations()) {
            $this->load();
        }

        $this->applyPagination();

        return new QueryFilterResult($this->getBuilder(), $totalCount, $sums ?? []);
    }

    protected function select(): void
    {
        $validAttributes = [];

        foreach ($this->getFilter()->getSelectedAttributes() as $attribute) {
            if ($this->getModelFilter()->hasSelectableAttribute($attribute)) {
                $validAttributes[] = $attribute;
            }
        }

        if (!empty($validAttributes)) {
            $this->getBuilder()->select($validAttributes);
        }
    }

    protected function applyFilterGroups(): void
    {
        foreach ($this->getFilter()->getFilterGroups() as $i => $filterGroup) {
            $this->applyFilters($filterGroup, $i);
        }
    }

    protected function applyFilters(array $filterGroup, int $filterGroupIndex): void
    {
        $this->getBuilder()->where(function (Builder $query) use ($filterGroup, $filterGroupIndex) {
            foreach ($filterGroup as $filterKey => $filter) {
                if ($this->filterRelations($query, $filter, $filterKey === 0)) {
                    continue;
                }

                if ($this->filterRelationsCount($this->getBuilder(), $filter, $filterGroupIndex === 0)) {
                    continue;
                }

                if ($this->getModelFilter()->hasFilterableAttribute($filter['field'])) {
                    $filterKey === 0 ? $this->where($query, $filter) : $this->orWhere($query, $filter);
                }
            }
        });
    }

    protected function where(Builder $query, array $filter): Builder
    {
        if ($this->isWhereNull($filter)) {
            return $this->whereNull($query, $filter['field']);
        }
        if ($this->isWhereNotNull($filter)) {
            return $this->whereNotNull($query, $filter['field']);
        }
        if ($this->isWhereIn($filter)) {
            return $this->whereIn($query, $filter);
        }
        if ($this->isWhereNotIn($filter)) {
            return $this->whereNotIn($query, $filter);
        }

        return $query->where($filter['field'], $filter['op'], $filter['value']);
    }

    protected function orWhere(Builder $query, array $filter): Builder
    {
        if ($this->isWhereNull($filter)) {
            return $this->whereNull($query, $filter['field'], true);
        }
        if ($this->isWhereNotNull($filter)) {
            return $this->whereNotNull($query, $filter['field'], true);
        }
        if ($this->isWhereIn($filter)) {
            return $this->whereIn($query, $filter, true);
        }
        if ($this->isWhereNotIn($filter)) {
            return $this->whereNotIn($query, $filter, true);
        }

        return $query->orWhere($filter['field'], $filter['op'], $filter['value']);
    }

    protected function whereIn(Builder $query, array $filter, bool $orCondition = false): Builder
    {
        if ($orCondition) {
            return $query->orWhereIn($filter['field'], $filter['value']);
        }

        return $query->whereIn($filter['field'], $filter['value']);
    }

    protected function whereNotIn(Builder $query, array $filter, bool $orCondition = false): Builder
    {
        if ($orCondition) {
            return $query->orWhereNotIn($filter['field'], $filter['value']);
        }

        return $query->whereNotIn($filter['field'], $filter['value']);
    }

    protected function whereNull(Builder $query, string $column, bool $orCondition = false): Builder
    {
        if ($orCondition) {
            return $query->orWhereNull($column);
        }

        return $query->whereNull($column);
    }

    protected function whereNotNull(Builder $query, string $column, bool $orCondition = false): Builder
    {
        if ($orCondition) {
            return $query->orWhereNotNull($column);
        }

        return $query->whereNotNull($column);
    }

    protected function having(Builder $query, array $filter): Builder
    {
        return $query->having($filter['field'], $filter['op'], $filter['value']);
    }

    protected function orHaving(Builder $query, array $filter): Builder
    {
        return $query->orHaving($filter['field'], $filter['op'], $filter['value']);
    }

    protected function filterRelations(Builder $query, array $filter, bool $firstKey = true): bool
    {
        if (($relationInfo = $this->getModelFilter()->hasFilterableRelation($filter['field'])) !== false) {
            [$relationName, $relationAttribute] = $relationInfo;
            $filter['field'] = $relationAttribute;

            $this->filterRelation($query, $filter, $relationName, !$firstKey);

            return true;
        }

        return false;
    }

    protected function filterRelationsCount(Builder $query, array $filter, bool $firstKey = true): bool
    {
        if (($relationInfo = $this->getModelFilter()->hasFilterableRelationCount($filter['field'])) !== false) {
            [$relationName, $relationCountAttribute, $closure] = $relationInfo;

            $filter['field'] = $relationCountAttribute;

            $this->filterRelationCount($query, $relationName, $closure, $filter, !$firstKey);

            return true;
        }

        return false;
    }

    protected function filterRelation(
        Builder $query,
        array $filter,
        string $relationName,
        bool $orCondition = false
    ): Builder {
        if (isset($filter['has']) && $filter['has'] === false) {
            if ($orCondition) {
                return $query->orWhereDoesntHave($relationName, function ($query) use ($filter) {
                    $this->where($query, $filter);
                });
            }

            return $query->whereDoesntHave($relationName, function ($query) use ($filter) {
                $this->where($query, $filter);
            });
        }

        if ($orCondition) {
            return $query->orWhereHas($relationName, function ($query) use ($filter) {
                $this->where($query, $filter);
            });
        }

        return $query->whereHas($relationName, function ($query) use ($filter) {
            $this->where($query, $filter);
        });
    }

    protected function filterRelationCount(
        Builder $query,
        string $relationName,
        ?Closure $closure,
        array $filter,
        bool $orCondition = false
    ): Builder {
        if (is_null($closure)) {
            $query->withCount("$relationName as {$filter['field']}");
        } else {
            $query->withCount([
                "$relationName as {$filter['field']}" => $closure,
            ]);
        }

        if ($orCondition) {
            return $this->orHaving($query, $filter);
        }

        return $this->having($query, $filter);
    }

    protected function applyPagination(): Builder
    {
        $maxLimit = $this->getModelFilter()->getMaxPaginationLimit();

        if (
            $maxLimit === -1
            && !$this->getFilter()->hasOffset()
            && !$this->getFilter()->hasLimit()
        ) {
            return $this->getBuilder();
        }

        if (!$this->getFilter()->hasOffset()) {
            $this->getFilter()->setOffset(0);
        }

        if (!$this->getFilter()->hasLimit() || $this->getFilter()->getLimit() > $maxLimit) {
            $this->getFilter()->setLimit($maxLimit);
        }

        return $this->paginate();
    }

    protected function paginate(): Builder
    {
        return $this->getBuilder()->limit($this->getFilter()->getLimit())->offset($this->getFilter()->getOffset());
    }

    protected function load(): void
    {
        foreach ($this->getFilter()->getRelations() as $relation) {
            if ($this->getModelFilter()->hasLoadableRelation($relation)) {
                $this->getBuilder()->with($relation);
            }
        }
    }

    protected function sort(): void
    {
        foreach ($this->getFilter()->getSorts() as $sort) {
            if ($this->getModelFilter()->hasSortableAttribute($sort['field'])) {
                $this->getBuilder()->orderBy($sort['field'], $sort['dir']);
            }
        }
    }

    protected function sum(): array
    {
        $sum = [];

        foreach ($this->getFilter()->getSums() as $sumField) {
            if ($this->getModelFilter()->hasSummableAttribute($sumField)) {
                $sum[$sumField] = $this->getBuilder()->sum($sumField);
            }
        }

        return $sum;
    }

    protected function isWhereIn(array $filter): bool
    {
        return ($filter['op'] === 'in' && is_array($filter['value']));
    }

    protected function isWhereNotIn(array $filter): bool
    {
        return ($filter['op'] === 'not' && is_array($filter['value']));
    }

    protected function isWhereNull(array $filter): bool
    {
        return ($filter['op'] === 'is' && $filter['value'] === null);
    }

    protected function isWhereNotNull(array $filter): bool
    {
        return ($filter['op'] === 'not' && $filter['value'] === null);
    }

    protected function getFilter(): Filter
    {
        return $this->getModelFilter()->getFilter();
    }

    protected function getModelFilter(): ModelFilter
    {
        return $this->modelFilter;
    }

    protected function getBuilder(): Builder
    {
        return $this->builder;
    }
}
