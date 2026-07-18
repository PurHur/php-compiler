<?php
declare(strict_types=1);

/**
 * Repro for #20438 — imageellipse / imagefilledellipse registration.
 */
foreach (['imageellipse', 'imagefilledellipse', 'imagecreatetruecolor'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
