<?php

declare(strict_types=1);

$fail = 0;

try {
    substr('abc', 0, 1, 99);
    fwrite(STDERR, "fail: substr excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '4 given')) {
        fwrite(STDERR, 'fail: substr message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: substr '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
