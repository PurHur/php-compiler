--TEST--
hash_update_file Reflection + named stream_context (VM, issue #24563)
--FILE--
<?php
$r = new ReflectionFunction('hash_update_file');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), PHP_EOL;
$td = sys_get_temp_dir().'/huf'.getmypid();
file_put_contents($td, 'abc');
$ctx = hash_init('md5');
hash_update_file(context: $ctx, filename: $td, stream_context: null);
echo hash_final($ctx), PHP_EOL;
$ctx2 = hash_init('md5');
hash_update_file($ctx2, $td);
echo hash_final($ctx2), PHP_EOL;
$ctx3 = hash_init('md5');
hash_update_file(context: $ctx3, filename: $td, stream_context: stream_context_create([]));
echo hash_final($ctx3), PHP_EOL;
try {
    hash_update_file(context: hash_init('md5'), filename: $td, context_resource: null);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
unlink($td);
--EXPECT--
context,filename,stream_context
arity=3 required=2
900150983cd24fb0d6963f7d28e17f72
900150983cd24fb0d6963f7d28e17f72
900150983cd24fb0d6963f7d28e17f72
Unknown named parameter $context_resource
