--TEST--
stdlib gzfile/readgzfile Reflection array|false / int|false (#28788, ext/zlib/zlib.stub.php)
--SKIPIF--
<?php if (!function_exists('gzfile') || !function_exists('readgzfile')) { print 'skip zlib gzfile unavailable'; } ?>
--FILE--
<?php
foreach (['gzfile', 'readgzfile'] as $fn) {
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
var_export(@gzfile('/no/such/gz'));
echo "\n";
?>
--EXPECT--
gzfile(string $filename, int $use_include_path=?): array|false
readgzfile(string $filename, int $use_include_path=?): int|false
false
