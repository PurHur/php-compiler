--TEST--
stdlib md5/sha1(null $binary) soft DEP+coerce outside strict_types (#31358, ext/standard/md5.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo md5('x', null), "\n";
    echo sha1('x', null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: md5(): Passing null to parameter #2 ($binary) of type bool is deprecated in %s on line %d
9dd4e461268c8034f5c8564e155c67a6
%ADeprecated: sha1(): Passing null to parameter #2 ($binary) of type bool is deprecated in %s on line %d
11f6ad8ec52a2984abaafd7c3b516503785c2072
