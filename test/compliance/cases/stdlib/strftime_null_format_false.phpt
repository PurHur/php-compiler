--TEST--
stdlib strftime()/gmstrftime() null format returns false (ext/standard/datetime.c, #18945)
--FILE--
<?php
echo strftime(null) === false ? "false\n" : "bad\n";
echo gmstrftime(null) === false ? "false\n" : "bad\n";
--EXPECT--
false
false
