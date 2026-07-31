<?php

declare(strict_types=1);

/**
 * #25627 — class extends missing parent must Error like Zend (autoload first).
 */
$loaded = [];
spl_autoload_register(static function (string $c) use (&$loaded): void {
    $loaded[] = $c;
});

try {
    class C extends NoSuchParentMaintGap {}
    echo "accepted\n";
} catch (Error $e) {
    echo 'caught ', get_class($e), ': ', $e->getMessage(), "\n";
    echo 'loaded=', json_encode($loaded), "\n";
    echo class_exists('C', false) ? "C_exists\n" : "C_missing\n";
}
echo "after\n";
