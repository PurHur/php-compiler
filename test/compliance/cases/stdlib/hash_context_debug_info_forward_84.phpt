--TEST--
Stdlib: HashContext::__debugInfo() algo-only on PROFILE=8.4 (#22563, #7084)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$ctx = hash_init('sha256');
echo 'method_exists=', method_exists($ctx, '__debugInfo') ? '1' : '0', "\n";
var_dump($ctx->__debugInfo());
var_dump($ctx);
--EXPECTF--
method_exists=1
array(1) {
  ["algo"]=>
  string(6) "sha256"
}
object(HashContext)#%d (1) {
  ["algo"]=>
  string(6) "sha256"
}
