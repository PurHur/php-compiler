--TEST--
AOT quotemeta()
--FILE--
<?php
echo quotemeta('a.b'), "\n";
echo quotemeta('x+y'), "\n";
--EXPECT--
a\.b
x\+y
