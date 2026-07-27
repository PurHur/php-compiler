--TEST--
openssl_random_pseudo_bytes named length/strong_result arguments (VM, issue #23626)
--FILE--
<?php
$rf = new ReflectionFunction('openssl_random_pseudo_bytes');
echo implode(',', array_map(fn($p) => $p->getName(), $rf->getParameters())), PHP_EOL;
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 4, strong_result: $strong);
echo strlen($bytes), ' ', (int)$strong, PHP_EOL;
try {
    openssl_random_pseudo_bytes(length: 4, returned_strong_result: $strong);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
length,strong_result
4 1
Unknown named parameter $returned_strong_result
