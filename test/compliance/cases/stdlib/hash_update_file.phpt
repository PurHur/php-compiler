--TEST--
stdlib hash_update_file() — incremental hash from file path (#14967)
--FILE--
<?php
echo function_exists('hash_update_file') ? "has-fn\n" : "missing-fn\n";

$path = __DIR__.'/hash_update_file.tmp';
file_put_contents($path, "hello\n");

$ctx = hash_init('sha256');
var_dump(hash_update_file($ctx, $path));

$expected = hash_file('sha256', $path);
$actual = hash_final($ctx);
echo $expected === $actual ? "match\n" : "mismatch\n";

unlink($path);
--EXPECT--
has-fn
bool(true)
match

