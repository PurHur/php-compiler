--TEST--
AOT md5() raw_output (#179)
--FILE--
<?php
echo bin2hex(md5('abc', true)), "\n";
--EXPECT--
900150983cd24fb0d6963f7d28e17f72
