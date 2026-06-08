--TEST--
stdlib hash_final() on finalized context TypeError (#7174)
--FILE--
<?php
$ctx = hash_init('sha256');
hash_final($ctx);
try {
    hash_final($ctx);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_final(): Argument #1 ($context) must be a valid, non-finalized HashContext
