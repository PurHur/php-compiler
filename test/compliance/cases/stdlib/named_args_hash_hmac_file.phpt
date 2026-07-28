--TEST--
hash_hmac_file Reflection binary + named args (VM, issue #24377)
--FILE--
<?php
$r = new ReflectionFunction('hash_hmac_file');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
$td = sys_get_temp_dir().'/hmf'.getmypid();
file_put_contents($td, 'x');
echo substr(hash_hmac_file(algo: 'sha256', filename: $td, key: 'k', binary: false), 0, 8), PHP_EOL;
echo substr(hash_hmac_file('sha256', $td, 'k', false), 0, 8), PHP_EOL;
try {
    hash_hmac_file(algo: 'sha256', filename: $td, key: 'k', raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
unlink($td);
--EXPECT--
algo,filename,key,binary
c38edc88
c38edc88
Unknown named parameter $raw_output
