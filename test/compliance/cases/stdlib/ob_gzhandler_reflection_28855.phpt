--TEST--
stdlib ob_gzhandler Reflection string|false (#28855, ext/zlib/zlib.stub.php)
--SKIPIF--
<?php if (!function_exists('ob_gzhandler')) { print 'skip ob_gzhandler unavailable'; } ?>
--FILE--
<?php
$r = new ReflectionFunction('ob_gzhandler');
$ps = [];
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
    $ps[] = $t . '$' . $p->getName();
}
echo 'ob_gzhandler(', implode(', ', $ps), ')';
echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
echo "\n";
?>
--EXPECT--
ob_gzhandler(string $data, int $flags): string|false
