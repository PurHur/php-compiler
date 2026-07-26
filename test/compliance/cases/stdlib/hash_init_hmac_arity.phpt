--TEST--
stdlib hash_init() HASH_HMAC + Reflection arity (#23585, ext/hash/hash.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('hash_init');
echo $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
echo defined('HASH_HMAC') ? HASH_HMAC : 'missing', "\n";
$c = hash_init('sha256', HASH_HMAC, 'secret');
hash_update($c, 'msg');
echo hash_final($c), "\n";
echo hash_hmac('sha256', 'msg', 'secret'), "\n";
try {
    hash_init('sha256', HASH_HMAC);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
4
algo
flags
key
options
1
fe4f9c418f683f034f6af90d1dd5b86ac0355dd96332c59cc74598d0736107f6
fe4f9c418f683f034f6af90d1dd5b86ac0355dd96332c59cc74598d0736107f6
hash_init(): Argument #3 ($key) cannot be empty when HMAC is requested
