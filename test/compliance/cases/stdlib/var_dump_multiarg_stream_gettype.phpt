--TEST--
Stdlib: var_dump($stream, gettype($stream)) — second arg is gettype string not stream (#11144, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$s = fopen('php://memory', 'r+');
ob_start();
var_dump($s, gettype($s));
$out = ob_get_clean();
if (!str_contains($out, 'string(8) "resource"')) {
    echo "fail: var_dump second arg not string resource\n";
    echo $out;
    exit(1);
}
try {
    $n = fwrite($s, 'hi');
} catch (Throwable $e) {
    echo 'fail: ', get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
if (2 !== $n) {
    echo 'fail: fwrite=', var_export($n, true), "\n";
    exit(1);
}
echo "ok\n";
--EXPECT--
ok
