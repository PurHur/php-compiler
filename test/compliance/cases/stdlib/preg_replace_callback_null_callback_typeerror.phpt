--TEST--
preg_replace_callback() null callback — TypeError message suffix (#14787)
--FILE--
<?php
try {
    preg_replace_callback('/a/', null, 'a');
    echo "unexpected_ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
preg_replace_callback(): Argument #2 ($callback) must be a valid callback, no array or string given
