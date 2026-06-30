--TEST--
Stdlib: array_reduce() callback Exception caught once (#14105, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = [1, 2];
try {
    array_reduce($a, static function (): void {
        throw new Exception('boom');
    });
} catch (Exception $e) {
}
echo "ok\n";
--EXPECT--
ok
