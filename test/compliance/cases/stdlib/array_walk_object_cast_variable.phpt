--TEST--
stdlib array_walk() — named (object) variable after ob_start() (#17989, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = (object) ['x' => 1];
ob_start();
array_walk($a, static fn ($v) => print($v));
$out = ob_get_clean();
if ('1' !== $out) {
    echo "fail: [{$out}]\n";
    exit(1);
}
echo "ok\n";
?>
--EXPECT--
ok
