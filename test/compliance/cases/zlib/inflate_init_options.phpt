--TEST--
zlib inflate_init() second $options arg + Reflection names (ext/zlib/zlib.stub.php, #23642)
--FILE--
<?php
declare(strict_types=1);
$rf = new ReflectionFunction('inflate_init');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$ctx = inflate_init(ZLIB_ENCODING_GZIP, []);
echo get_debug_type($ctx), "\n";
$named = inflate_init(encoding: ZLIB_ENCODING_RAW, options: []);
echo get_debug_type($named), "\n";
try {
    inflate_init(ZLIB_ENCODING_GZIP, ['window' => 7]);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
encoding
options
InflateContext
InflateContext
zlib window size (logarithm) (7) must be within 8..15
