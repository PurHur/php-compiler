--TEST--
hash_update_stream Reflection + named stream (VM, issue #23786)
--FILE--
<?php
$r = new ReflectionFunction('hash_update_stream');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), PHP_EOL;
$td = sys_get_temp_dir().'/hus'.getmypid();
file_put_contents($td, 'hello');
$h = fopen($td, 'r');
$ctx = hash_init('sha1');
hash_update_stream(context: $ctx, stream: $h, length: 2);
echo hash_final($ctx), PHP_EOL;
fclose($h);
$h2 = fopen($td, 'r');
$ctx2 = hash_init('sha1');
hash_update_stream($ctx2, $h2);
echo hash_final($ctx2), PHP_EOL;
fclose($h2);
try {
    hash_update_stream(context: hash_init('sha1'), handle: fopen($td, 'r'));
    echo "legacy handle accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
unlink($td);
--EXPECT--
context,stream,length
arity=3 required=2
30f088ea6673877c2e2c1edbe7513ff90eda9a6f
aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d
Unknown named parameter $handle
