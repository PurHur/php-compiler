--TEST--
stdlib strrpos() JIT negative offset suffix window (issue #4104)
--FILE--
<?php
declare(strict_types=1);
echo strrpos('abcabc', 'bc', -3), "\n";
echo strrpos('abcabc', 'bc', -1), "\n";
--EXPECT--
1
4
