--TEST--
PCRE preg_quote null $str TypeError on 8.4 (#19320; match_all/split soft #21318)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    preg_quote(null);
    echo "preg_quote: uncaught\n";
} catch (TypeError $e) {
    echo 'preg_quote: '.$e->getMessage()."\n";
}
?>
--EXPECT--
preg_quote: preg_quote(): Argument #1 ($str) must be of type string, null given
