<?php

declare(strict_types=1);

foreach (['file', 'get_headers', 'get_meta_tags'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
