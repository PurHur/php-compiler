--TEST--
AOT phpversion() — surplus args ArgumentCountError (#17196, ext/standard/info.c)
--FILE--
<?php
try {
    phpversion('pcre', 'extra');
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
phpversion() expects at most 1 argument, 2 given
