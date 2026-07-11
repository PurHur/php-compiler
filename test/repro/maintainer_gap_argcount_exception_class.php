<?php

declare(strict_types=1);

$fail = 0;

foreach (['array_push', 'array_slice', 'array_splice'] as $fn) {
    try {
        $fn();
        fwrite(STDERR, "fail: {$fn}() zero args no exception\n");
        $fail = 1;
    } catch (ArgumentCountError $e) {
        if (!str_contains($e->getMessage(), '0 given')) {
            fwrite(STDERR, "fail: {$fn} message=".$e->getMessage()."\n");
            $fail = 1;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "fail: {$fn} ".get_class($e).': '.$e->getMessage()."\n");
        $fail = 1;
    }
}

exit($fail);
