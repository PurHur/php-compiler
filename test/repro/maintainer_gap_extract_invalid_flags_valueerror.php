<?php

declare(strict_types=1);

$fail = 0;

try {
    extract(['a' => 1], 99999);
    fwrite(STDERR, "fail: extract invalid flags no exception\n");
    $fail = 1;
} catch (\ValueError $e) {
    if (!str_contains($e->getMessage(), 'valid extract type')) {
        fwrite(STDERR, 'fail: extract message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: extract '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
