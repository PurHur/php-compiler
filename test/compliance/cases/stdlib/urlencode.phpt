--TEST--
stdlib urlencode() and rawurlencode()
--FILE--
<?php
echo urlencode('a b'), "\n";
echo urlencode('x&y'), "\n";
echo rawurlencode('a b'), "\n";
--EXPECT--
a+b
x%26y
a%20b
