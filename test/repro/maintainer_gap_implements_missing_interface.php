<?php

declare(strict_types=1);

/**
 * Repro #25624 — implements missing interface must Error after autoload (Zend zend_execute_API.c).
 */
$loaded = [];
spl_autoload_register(static function (string $c) use (&$loaded): void {
    $loaded[] = $c;
});
class C implements NoSuchIfaceMaintGap {}
echo "accepted\n";
echo 'loaded=', json_encode($loaded), "\n";
