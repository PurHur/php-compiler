--TEST--
stdlib quotemeta() JIT
--FILE--
<?php
echo quotemeta('[route]'), "\n";
--EXPECT--
\[route\]
