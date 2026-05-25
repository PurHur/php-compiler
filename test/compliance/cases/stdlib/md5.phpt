--TEST--
stdlib md5() hex digest
--FILE--
<?php
echo md5('abc'), "\n";
echo md5('body'), "\n";
--EXPECT--
900150983cd24fb0d6963f7d28e17f72
841a2d689ad86bd1611447453c22c6fc
