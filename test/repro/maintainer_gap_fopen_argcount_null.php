<?php

declare(strict_types=1);

$fail = 0;

try {
    fopen(null);
    fwrite(STDERR, "fail: fopen(null) no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '1 given')) {
        fwrite(STDERR, 'fail: fopen message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: fopen '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

try {
    file_put_contents(null);
    fwrite(STDERR, "fail: file_put_contents(null) no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '1 given')) {
        fwrite(STDERR, 'fail: file_put_contents message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: file_put_contents '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
