--TEST--
stdlib deflate_init/inflate_init Reflection DeflateContext|false / InflateContext|false (#27627, ext/zlib/zlib.stub.php)
--FILE--
<?php
foreach (['deflate_init', 'inflate_init'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', $p->getName(), ':', $t ? (string) $t : 'none', $p->isOptional() ? '=opt' : '', PHP_EOL;
    }
}
$ctx = deflate_init(encoding: ZLIB_ENCODING_DEFLATE, options: []);
echo 'type=', get_debug_type($ctx), PHP_EOL;
echo 'out_len=', strlen(deflate_add($ctx, 'hello', ZLIB_FINISH)), PHP_EOL;
$inf = inflate_init(encoding: ZLIB_ENCODING_DEFLATE, options: []);
echo 'inf=', get_debug_type($inf), PHP_EOL;
?>
--EXPECT--
deflate_init return=DeflateContext|false
  encoding:int
  options:array=opt
inflate_init return=InflateContext|false
  encoding:int
  options:array=opt
type=DeflateContext
out_len=13
inf=InflateContext
