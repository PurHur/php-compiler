--TEST--
stdlib hash_hmac_algos Reflection return array (#27942, ext/hash/hash.stub.php)
--FILE--
<?php
$hmac = new ReflectionFunction('hash_hmac_algos');
$algos = new ReflectionFunction('hash_algos');
echo 'hmac_ret=', $hmac->hasReturnType() ? (string) $hmac->getReturnType() : 'NONE', "\n";
echo 'algos_ret=', $algos->hasReturnType() ? (string) $algos->getReturnType() : 'NONE', "\n";
echo 'hmac_arity=', $hmac->getNumberOfParameters(), "\n";
$list = hash_hmac_algos();
echo 'runtime_nonempty=', (\is_array($list) && \count($list) > 0) ? 'Y' : 'N', "\n";
echo 'has_sha256=', \in_array('sha256', $list, true) ? 'Y' : 'N', "\n";
?>
--EXPECT--
hmac_ret=array
algos_ret=array
hmac_arity=0
runtime_nonempty=Y
has_sha256=Y
