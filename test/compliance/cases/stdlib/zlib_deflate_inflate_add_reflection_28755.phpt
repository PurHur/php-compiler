--TEST--
stdlib deflate_add/inflate_add Reflection DeflateContext/InflateContext → string|false (#28755, ext/zlib/zlib.stub.php)
--SKIPIF--
<?php if (!function_exists('deflate_add') || !function_exists('inflate_add')) { print 'skip zlib incremental unavailable'; } ?>
--FILE--
<?php
foreach (['deflate_add', 'inflate_add'] as $fn) {
    $r = new ReflectionFunction($fn);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $opt = $p->isOptional() ? '=?' : '';
        $ps[] = $t . '$' . $p->getName() . $opt;
    }
    echo $fn, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$ctx = deflate_init(ZLIB_ENCODING_DEFLATE);
$blob = deflate_add(context: $ctx, data: 'hello', flush_mode: ZLIB_FINISH);
echo 'roundtrip_len=', strlen($blob), "\n";
?>
--EXPECT--
deflate_add(DeflateContext $context, string $data, int $flush_mode=?): string|false
inflate_add(InflateContext $context, string $data, int $flush_mode=?): string|false
roundtrip_len=13
