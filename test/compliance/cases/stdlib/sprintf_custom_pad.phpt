--TEST--
stdlib sprintf()/vsprintf() custom pad %'<char> — php-src formatted_print.c (#22833)
--FILE--
<?php
echo sprintf("%'*20s", 'x'), "\n";
echo sprintf("%'-10s", 'x'), "\n";
echo sprintf("%'*10d", 7), "\n";
echo vsprintf("%'*8s", ['x']), "\n";
echo sprintf("%1$'*10s", 'x'), "\n";
echo sprintf("%'#8s", 'ab'), "\n";
echo sprintf("%'*+10d", 7), "\n";
echo sprintf("%+'*10d", 7), "\n";
echo sprintf("%0'*8d", 7), "\n";
echo sprintf("%-'*10s", 'x'), "\n";
try {
    echo sprintf("%'", 'x'), "\n";
} catch (ValueError $e) {
    echo "ValueError:", $e->getMessage(), "\n";
}
--EXPECT--
*******************x
---------x
*********7
*******x
*********x
######ab
********+7
********+7
*******7
x*********
ValueError:Missing padding character
