--TEST--
stdlib preg_grep(null $flags) soft DEP+coerce outside strict_types (#31385, ext/pcre/php_pcre.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $got = preg_grep('/a/', ['a', 'b', 'aa'], null);
    echo implode(',', $got), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: preg_grep(): Passing null to parameter #3 ($flags) of type int is deprecated in %s on line %d
a,aa
