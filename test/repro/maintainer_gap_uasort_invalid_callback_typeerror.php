<?php
declare(strict_types=1);

foreach (['uasort', 'usort', 'uksort'] as $fn) {
    try {
        $a = [1, 2];
        $fn($a, 'not_a_function');
        echo "fail: {$fn}() uncaught\n";
        exit(1);
    } catch (\TypeError $e) {
        if (!str_contains($e->getMessage(), 'must be a valid callback')) {
            echo "fail: {$fn}() unexpected: {$e->getMessage()}\n";
            exit(1);
        }
    } catch (\Throwable $e) {
        echo 'fail: '.$fn.'() threw '.get_class($e).': '.$e->getMessage()."\n";
        exit(1);
    }
}

echo "ok\n";
