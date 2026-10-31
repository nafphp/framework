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
        $collection->add('nullable');
        $this->assertTrue($collection->has('foo'));
        $this->assertTrue($collection->has('nullable'));
        $this->assertSame('bar', $collection->get('foo'));
        $this->assertNull($collection->get('nullable'));
        $this->assertSame(['foo' => 'bar', 'nullable' => null], $collection->all());
    }

}