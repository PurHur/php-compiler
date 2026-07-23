--TEST--
Stdlib: HashContext — no __debugInfo on PROFILE=8.2 (#22563, ext/hash/hash.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);

$ctx = hash_init('sha256');
echo 'method_exists=', method_exists($ctx, '__debugInfo') ? '1' : '0', "\n";
echo 'gcm=', json_encode(get_class_methods($ctx)), "\n";
var_dump($ctx);
--EXPECTF--
method_exists=0
gcm=["__serialize","__unserialize"]
object(HashContext)#%d (0) {
}
