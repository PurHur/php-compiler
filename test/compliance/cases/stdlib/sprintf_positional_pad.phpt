--TEST--
stdlib sprintf() positional width/zero-pad — issue #9067
--FILE--
<?php
echo sprintf('%3$02d', 1, 2, 3), "\n";
echo sprintf('%2$05d', 9, 42), "\n";
echo sprintf('%2$-5s', 'x', 'abc'), "\n";
echo vsprintf('%3$02d', [1, 2, 3]), "\n";
printf("%2$05d\n", 9, 42);
--EXPECT--
03
00042
abc  
03
00042
