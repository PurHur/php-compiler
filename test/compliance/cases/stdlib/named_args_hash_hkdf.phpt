--TEST--
hash_hkdf algo/key/length/info/salt named args (VM, issue #23290)
--FILE--
<?php
echo bin2hex(hash_hkdf(algo: 'sha256', key: 'ikm', length: 8, info: 'i', salt: 's')), PHP_EOL;
$rf = new ReflectionFunction('hash_hkdf');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), PHP_EOL;
echo $rf->getNumberOfParameters(), PHP_EOL;
--EXPECT--
b069c08f611a5338
algo,key,length,info,salt
5
