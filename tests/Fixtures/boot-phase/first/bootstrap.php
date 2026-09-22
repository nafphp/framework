<?php
$GLOBALS['bootOrder'][] = 'first';
\Naf\event()->listen(\Naf\Core\Event::PLUGINS_BOOTED, static function (): void {
    foreach (\Naf\app()->getPlugins() as $plugin) {
        if (!$plugin->isBooted()) {
            throw new RuntimeException('An extension has not finished booting.');
        }
    }
    $GLOBALS['bootOrder'][] = 'ready';
});
