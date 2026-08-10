<?php

declare(strict_types=1);

foreach (['stristr', 'strchr', 'strrchr'] as $fn) {
    try {
        $r = $fn(null, 'a');
        echo "fail:$fn:";
        var_export($r);
        echo "\n";
    } catch (TypeError $e) {
        echo 'ok:', $fn, ':', $e->getMessage(), "\n";
    }
}
