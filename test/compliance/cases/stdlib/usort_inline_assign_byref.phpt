--TEST--
stdlib usort() inline assign haystack throws by-ref Error not TypeError (#17950, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

try {
    usort($items = explode(',', '3,1,2'), static fn ($a, $b): int => $a <=> $b);
    echo "fail\n";
    exit(1);
} catch (\Error $e) {
    if (!str_contains($e->getMessage(), 'could not be passed by reference')) {
        echo 'fail: '.$e->getMessage()."\n";
        exit(1);
    }
} catch (\Throwable $e) {
    echo 'fail wrong class: '.get_class($e)."\n";
    exit(1);
}

echo "ok\n";
?>
--EXPECT--
ok
