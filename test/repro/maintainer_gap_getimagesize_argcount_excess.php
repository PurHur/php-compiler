<?php

declare(strict_types=1);

$fail = 0;
$info = [];

try {
    getimagesize('/dev/null', $info, 99);
    fwrite(STDERR, "fail: getimagesize excess args no exception\n");
    $fail = 1;
} catch (\ArgumentCountError $e) {
    if (!str_contains($e->getMessage(), '3 given')) {
        fwrite(STDERR, 'fail: getimagesize message='.$e->getMessage()."\n");
        $fail = 1;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: getimagesize '.get_class($e).': '.$e->getMessage()."\n");
    $fail = 1;
}

exit($fail);
