<?php

declare(strict_types=1);

$fail = 0;

try {
    explode('', 'a', 0, 99);
    fwrite(STDERR, "fail: explode excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '4 given')) {
        fwrite(STDERR, 'fail: explode message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: explode '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
