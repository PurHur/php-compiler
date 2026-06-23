--TEST--
stdlib preg_replace() array $pattern and $replacement (#10808, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace(['/a/', '/b/'], ['A', 'B'], 'ab'), "\n";
echo preg_replace(['/a/', '/b/'], 'X', 'ab'), "\n";
try {
    preg_replace(['/a/'], ['A', 'B'], 'x');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
AB
XX
preg_replace(): Argument #1 ($pattern) and argument #2 ($replacement) must have the same length
