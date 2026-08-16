<?php
/** trim/ltrim/rtrim/chop(..., null) $characters under strict_types */
declare(strict_types=1);
error_reporting(E_ALL);
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        var_export($fn(' x ', null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
