--TEST--
stdlib md5() JIT path
--FILE--
<?php
echo md5('body'), "\n";
echo hash('md5', 'body'), "\n";
--EXPECT--
841a2d689ad86bd1611447453c22c6fc
841a2d689ad86bd1611447453c22c6fc
