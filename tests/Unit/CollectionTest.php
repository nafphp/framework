<?php

namespace Tests\Unit;

use Naf\Support\Collection;
use Tests\NafTestCase;

class CollectionTest extends NafTestCase
{

    public function testCollectionInternals()
    {
        $collection = new Collection();
        $collection->add('foo', 'bar');
        $this->assertTrue($collection->has('foo'));
        $this->assertSame('bar', $collection->get('foo'));
        $this->assertSame(['foo' => 'bar'], $collection->all());
    }

}