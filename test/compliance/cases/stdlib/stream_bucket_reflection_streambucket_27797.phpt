--TEST--
stdlib stream_bucket_* Reflection StreamBucket stubs under PROFILE=8.4 (#27797)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsStreamBucketClass()) {
    die('skip StreamBucket Reflection needs PROFILE≥8.4');
}
?>
--FILE--
<?php
foreach (['stream_bucket_new', 'stream_bucket_make_writeable', 'stream_bucket_append', 'stream_bucket_prepend'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = ($p->hasType() ? (string) $p->getType() : '') . ' $' . $p->getName();
    }
    echo $f, '(', implode(', ', $ps), '): ', $r->hasReturnType() ? (string) $r->getReturnType() : '', PHP_EOL;
}
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'x');
echo 'runtime=', get_class($b), PHP_EOL;
?>
--EXPECT--
stream_bucket_new( $stream, string $buffer): StreamBucket
stream_bucket_make_writeable( $brigade): ?StreamBucket
stream_bucket_append( $brigade, StreamBucket $bucket): void
stream_bucket_prepend( $brigade, StreamBucket $bucket): void
runtime=StreamBucket
