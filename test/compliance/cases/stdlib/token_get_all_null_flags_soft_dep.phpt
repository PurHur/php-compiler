--TEST--
stdlib token_get_all(null $flags) soft DEP+coerce outside strict_types (#31361, ext/tokenizer/tokenizer.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $tokens = token_get_all('<?php echo 1;', null);
    echo count($tokens), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: token_get_all(): Passing null to parameter #2 ($flags) of type int is deprecated in %s on line %d
5
