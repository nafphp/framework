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
    try {
        extract($vars, EXTR_SKIP);
        include $template;

        return (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();

        throw $e;
    }
}
