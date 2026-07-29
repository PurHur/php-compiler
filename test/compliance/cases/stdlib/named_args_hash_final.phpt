--TEST--
hash_final Reflection binary + named args (VM, issue #23586)
--FILE--
<?php
$r = new ReflectionFunction('hash_final');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
$c = hash_init('sha256');
hash_update($c, 'x');
echo hash_final(context: $c, binary: false), PHP_EOL;
$c2 = hash_init('sha256');
hash_update($c2, 'x');
echo hash_final($c2, false), PHP_EOL;
$c3 = hash_init('sha256');
hash_update($c3, 'x');
try {
    hash_final(context: $c3, raw_output: false);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
context,binary
2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881
2d711642b726b04401627ca9fbac32f5c8530fb1903cc4db02258717921a4881
Unknown named parameter $raw_output
