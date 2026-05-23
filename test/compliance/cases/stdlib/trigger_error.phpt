--TEST--
Stdlib: trigger_error() notice continues (VM, #1221)
--FILE--
<?php
trigger_error('test notice', 1024);
echo trigger_error('again', 1024) ? '1' : '0';
echo "\nok\n";
--EXPECT--
1
ok
