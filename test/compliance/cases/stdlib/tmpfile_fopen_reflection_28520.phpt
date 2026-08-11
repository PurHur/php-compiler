--TEST--
stdlib tmpfile/fopen Reflection untyped return (#28520, basic_functions.stub.php)
--FILE--
<?php
foreach (['tmpfile', 'fopen'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped', PHP_EOL;
}
?>
--EXPECT--
tmpfile untyped
fopen untyped
