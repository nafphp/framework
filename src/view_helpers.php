<?php
declare(strict_types=1);

namespace Naf;

use Psr\Http\Message\ResponseInterface;

/**
 * @param string $template
 * @param array $vars
 * @return ResponseInterface
 * @internal
 */
function simple_render(string $template, array $vars = []): ResponseInterface
{
    return response(simple_view($template, $vars));
}

/**
 * @param string $template
 * @param array $vars
 * @return string
 * @internal
 */
function simple_view(string $template, array $vars = []): string
{
    ob_start();
    extract($vars);
    include $template;
    return ob_get_clean();
}
