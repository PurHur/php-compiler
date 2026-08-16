<?php
declare(strict_types=1);
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        var_export($fn(' x ', null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
