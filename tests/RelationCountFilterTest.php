<?php

namespace Omalizadeh\QueryFilter\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Omalizadeh\QueryFilter\Filter;
use Omalizadeh\QueryFilter\Tests\Filters\UserFilter;
use Omalizadeh\QueryFilter\Tests\Models\User;

class RelationCountFilterTest extends TestCase
{
    use RefreshDatabase;

    public function testSimpleRelationCountFilter(): void
    {
        $filter = new Filter();

        $filter->setFilterGroups([
            [
                [
                    'field' => 'posts_count',
                    'op' => '=',
                    'value' => 1,
                ],
            ],
        ]);

        $filterResult = User::filter(new UserFilter($filter));

        $filterResult->builder()->withCount('posts');

        foreach ($filterResult->data() as $user) {
            $this->assertEquals(1, $user->posts()->count());
        }
    }
}
