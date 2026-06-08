--TEST--
stdlib hash_update() enum case data TypeError (#7174, php-src-strict)
--FILE--
<?php
enum E: string { case X = 'data'; }
$ctx = hash_init('sha256');
try {
    hash_update($ctx, E::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_update(): Argument #2 ($data) must be of type string, E given
