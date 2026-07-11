--TEST--
Stdlib: usort() callback Exception unwinds to caller catch (#14104, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = [1, 2];
$out = '';
try {
    usort($a, static function () use (&$out): void {
        $out = 'threw';
        throw new Exception('boom');
    });
    $out .= ':after';
} catch (Exception $e) {
    $out .= ':caught';
}
echo $out, "\n";
--EXPECT--
threw:caught
