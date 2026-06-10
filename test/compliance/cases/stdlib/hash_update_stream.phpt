--TEST--
stdlib hash_update_stream() php://memory incremental SHA-256 (#6681)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, 'hello world');
rewind($h);
$ctx = hash_init('sha256');
$n = hash_update_stream($ctx, $h);
echo "bytes=$n\n";
echo hash_final($ctx), "\n";

$h2 = fopen('php://memory', 'r+');
fwrite($h2, 'hello world');
rewind($h2);
$ctx2 = hash_init('sha256');
$n2 = hash_update_stream($ctx2, $h2, 5);
echo "partial-bytes=$n2\n";
echo hash_final($ctx2), "\n";

$h3 = fopen('php://memory', 'r+');
fwrite($h3, 'x');
rewind($h3);
$ctx3 = hash_init('sha256');
$n3 = hash_update_stream($ctx3, $h3, 0);
echo "zero-bytes=$n3\n";
?>
--EXPECT--
bytes=11
b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9
partial-bytes=5
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
zero-bytes=0
