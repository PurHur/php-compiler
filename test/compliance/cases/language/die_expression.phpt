--TEST--
Language: (die) as expression terminates script (#3539)
--FILE--
<?php
$x = (die);
echo "never\n";
--EXPECT--
