<?php

namespace Fixtures\Enums;

use Naf\Core\Event;

class CustomEvent extends Event
{
    const string TEST_EVENT = 'test.event';
}