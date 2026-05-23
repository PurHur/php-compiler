--TEST--
stdlib preg_quote() JIT
--FILE--
<?php
echo preg_quote('[route]', '/'), "\n";
--EXPECT--
\[route\]
