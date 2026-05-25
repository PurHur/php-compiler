--TEST--
AOT sha1() raw_output (#2160)
--FILE--
<?php
echo bin2hex(sha1('abc', true)), "\n";
--EXPECT--
a9993e364706816aba3e25717850c26c9cd0d89d
