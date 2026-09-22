<?php

declare(strict_types=1);

namespace Naf\Core;

/**
 * This class is meant to be extended by the user to define custom events, but it is not required.
 */
class Event
{
    const string PLUGINS_BOOTED = 'plugins.booted'; // All plugin bootstraps finished; host routes have not loaded
    const string CONTROLLER_CALLING = 'controller.calling'; // Before the controller is called
    const string CONTROLLER_CALLED = 'controller.called';   // After the controller is called
    const string ROUTE_MATCHING = 'route.matching';         // Route is about to match or not
    const string ROUTE_MATCHED = 'route.matched';           // Route has matched
    const string ROUTE_NOT_FOUND = 'route.not_found';       // Route not found
    const string REQUEST_START = 'request.start';           // Before the app starts to handle the request
    const string REQUEST_BODY = 'request.body';             // Before the body is parsed
    const string REQUEST_END = 'request.end';               // Not in use yet
    const string RESPONSE_SEND = 'response.send';           // Before the app starts to send a response
    const string RESPONSE_BODY = 'response.body';           // Before the body is sent to the client
    const string RESPONSE_HEADER = 'response.header';       // Before the headers are sent to the client
    const string RESPONSE_END = 'response.end';             // Before the app shuts down
    const string EXCEPTION = 'exception';                   // When somewhere in the app, an exception is thrown
}
