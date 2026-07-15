<?php

declare(strict_types=1);

foreach (['stripcslashes', 'strtolower'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "$fn: uncaught\n");
        exit(1);
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
