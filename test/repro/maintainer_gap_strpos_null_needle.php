<?php

$fail = 0;

foreach (['strpos', 'stripos'] as $fn) {
    $result = $fn('abc', null);
    if (0 !== $result) {
        fwrite(STDERR, "$fn('abc', null): expected int(0), got " . var_export($result, true) . "\n");
        ++$fail;
    } else {
        echo "$fn: ok\n";
    }
}

exit($fail);
