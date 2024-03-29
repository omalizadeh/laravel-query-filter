<?php

namespace Omalizadeh\QueryFilter\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Omalizadeh\QueryFilter\Filter;

class FilterTest extends TestCase
{
    use RefreshDatabase;

    public function testAttributeFilterExists(): void
    {
        $filter = new Filter();

        $filter->addFilter('testing_attr_2', null);
        $filter->addFilter('testing_attr', '<=', 100);

        $this->assertTrue($filter->hasAnyFilterOn('testing_attr'));
    }

    public function testAttributeFilterDoesNotExist(): void
    {
        $filter = new Filter();

        $filter->addFilter('testing_attr', '=', 'value');

        $this->assertFalse($filter->hasAnyFilterOn('first_name'));
        $this->assertFalse($filter->hasAnyFilterOn('paid_amount'));
    }

    public function testRelationExistsOnFilter(): void
    {
        $filter = new Filter();

        $filter->addRelation('testing_relation');

        $this->assertTrue($filter->hasRelationOn('testing_relation'));
    }

    public function testRelationDoesNotExistOnFilter(): void
    {
        $filter = new Filter();

        $filter->addRelation('testing_relation');

        $this->assertFalse($filter->hasRelationOn('missing_relation'));
    }
}
