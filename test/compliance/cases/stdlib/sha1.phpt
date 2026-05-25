--TEST--
stdlib sha1() hex digest
--FILE--
<?php
echo sha1('abc'), "\n";
echo sha1('body'), "\n";
--EXPECT--
a9993e364706816aba3e25717850c26c9cd0d89d
02083f4579e08a612425c0c1a17ee47add783b94
