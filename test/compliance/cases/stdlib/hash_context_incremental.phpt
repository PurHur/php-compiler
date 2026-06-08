--TEST--
stdlib hash_init()/hash_update()/hash_final()/hash_copy() incremental SHA-256 (#7174)
--FILE--
<?php
$ctx = hash_init('sha256');
hash_update($ctx, 'hello ');
hash_update($ctx, 'world');
$ctx2 = hash_copy($ctx);
echo hash_final($ctx), "\n";
echo hash_final($ctx2), "\n";

$ctx = hash_init('sha256');
hash_update($ctx, 'hello');
$ctx2 = hash_copy($ctx);
hash_update($ctx2, ' world');
echo hash_final($ctx), "\n";
echo hash_final($ctx2), "\n";
?>
--EXPECT--
b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9
b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9
2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824
b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9
