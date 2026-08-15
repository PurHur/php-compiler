--TEST--
stdlib hash(null $binary) soft DEP+coerce outside strict_types (#31288, ext/hash/hash.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo hash('md5', 'a', null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: hash(): Passing null to parameter #3 ($binary) of type bool is deprecated in %s on line %d
0cc175b9c0f1b6a831c399e269772661
