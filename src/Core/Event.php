<?php

declare(strict_types=1);

namespace Naf\Core;

/**
 * This class is meant to be extended by the user to define custom events, but it is not required.
 */
class Event
{
    public const string CONTROLLER_CALLING = 'controller.calling'; // Before the controller is called
    public const string CONTROLLER_CALLED  = 'controller.called';   // After the controller is called
    public const string ROUTE_MATCHING     = 'route.matching';         // Route is about to match or not
    public const string ROUTE_MATCHED      = 'route.matched';           // Route has matched
    public const string ROUTE_NOT_FOUND    = 'route.not_found';       // Route not found
    public const string REQUEST_START      = 'request.start';           // Before the app starts to handle the request
    /** @deprecated Reserved compatibility constant; never dispatched by the core. */
    public const string REQUEST_BODY = 'request.body';
    /** @deprecated Reserved compatibility constant; never dispatched by the core. */
    public const string REQUEST_END     = 'request.end';
    public const string RESPONSE_SEND   = 'response.send';           // Before the app starts to send a response
    public const string RESPONSE_BODY   = 'response.body';           // Before the body is sent to the client
    public const string RESPONSE_HEADER = 'response.header';       // Before the headers are sent to the client
    public const string RESPONSE_END    = 'response.end';             // Before the app shuts down
    public const string EXCEPTION       = 'exception';                   // When somewhere in the app, an exception is thrown
}
