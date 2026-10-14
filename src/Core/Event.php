<?php

declare(strict_types=1);

namespace Naf\Core;

/**
 * This class is meant to be extended by the user to define custom events, but it is not required.
 */
class Event
{
    public const string CONTROLLER_CALLING = 'controller.calling'; // Before invoking the route handler
    public const string CONTROLLER_CALLED  = 'controller.called'; // After the route handler returns
    public const string ROUTE_MATCHING     = 'route.matching'; // Before matching the path and method
    public const string ROUTE_MATCHED      = 'route.matched'; // After selecting a route
    public const string ROUTE_NOT_FOUND    = 'route.not_found'; // When no path matches
    public const string REQUEST_START      = 'request.start'; // After registering the current request
    /** @deprecated Reserved compatibility constant; never dispatched by the core. */
    public const string REQUEST_BODY = 'request.body';
    /** @deprecated Reserved compatibility constant; never dispatched by the core. */
    public const string REQUEST_END     = 'request.end';
    public const string RESPONSE_SEND   = 'response.send'; // During request finalization, before emission
    public const string RESPONSE_BODY   = 'response.body'; // After emitting headers, before emitting the body
    public const string RESPONSE_HEADER = 'response.header'; // Before emitting headers; accepts a replacement response
    public const string RESPONSE_END    = 'response.end'; // After emitting the body
    public const string EXCEPTION       = 'exception'; // While handling an exception
}
