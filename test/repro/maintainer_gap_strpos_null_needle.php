<?php

declare(strict_types=1);

$fail = 0;

foreach (['strpos', 'substr_compare'] as $fn) {
    try {
        if ('strpos' === $fn) {
            $fn('haystack', null);
        } else {
            $fn('a', null, 0);
        }
        fwrite(STDERR, "$fn: expected TypeError on null needle\n");
        ++$fail;
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}

exit($fail);
