<?php

declare(strict_types=1);

$fail = 0;

try {
    checkdnsrr('', 'A');
    echo "fail: no throw\n";
    $fail = 1;
} catch (ValueError $e) {
    if ('checkdnsrr(): Argument #1 ($hostname) cannot be empty' !== $e->getMessage()) {
        echo 'bad message: '.$e->getMessage()."\n";
        $fail = 1;
    } else {
        echo "ok: ValueError\n";
    }
}

exit($fail);
