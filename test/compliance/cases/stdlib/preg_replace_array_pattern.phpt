--TEST--
stdlib preg_replace() array $pattern and $replacement (#10808, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace(['/a/', '/b/'], ['A', 'B'], 'ab'), "\n";
echo preg_replace(['/a/', '/b/'], 'X', 'ab'), "\n";
echo preg_replace(['/a/'], ['A', 'B'], 'a'), "\n";
--EXPECT--
AB
XX
A
