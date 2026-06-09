--TEST--
Stdlib: HashContext::__debugInfo() — var_dump hides digest state (#7084)
--FILE--
<?php
declare(strict_types=1);

$ctx = hash_init('sha256');
var_dump($ctx->__debugInfo());
var_dump($ctx);
--EXPECTF--
array(1) {
  ["algo"]=>
  string(6) "sha256"
}
object(HashContext)#%d (1) {
  ["algo"]=>
  string(6) "sha256"
}
