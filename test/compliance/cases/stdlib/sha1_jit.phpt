--TEST--
stdlib sha1() JIT path
--FILE--
<?php
echo sha1('body'), "\n";
echo hash('sha1', 'body'), "\n";
--EXPECT--
02083f4579e08a612425c0c1a17ee47add783b94
02083f4579e08a612425c0c1a17ee47add783b94
