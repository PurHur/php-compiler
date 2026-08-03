--TEST--
AOT get_headers() refused connection returns false (#27317, ext/standard/head.c)
--FILE--
<?php
echo var_export(@get_headers('http://127.0.0.1:1/') === false, true), "\n";
--EXPECT--
true
