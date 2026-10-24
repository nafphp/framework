<?php

namespace Fixtures\Events;

use RuntimeException;

class FailingEventListener
{
    public function handle(): never
    {
        throw new RuntimeException('class listener failed');
    }
}
