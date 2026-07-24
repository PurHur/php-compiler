--TEST--
stdlib sprintf()/vsprintf() custom pad %'<char> JIT/AOT (#22833)
--FILE--
<?php
echo sprintf("%'*20s", 'x'), "\n";
echo sprintf("%'*10d", 7), "\n";
echo vsprintf("%'*8s", ['x']), "\n";
echo sprintf("%1$'*10s", 'x'), "\n";
echo sprintf("%-'*10s", 'x'), "\n";
--EXPECT--
*******************x
*********7
*******x
*********x
x*********
