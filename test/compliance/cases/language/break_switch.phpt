--TEST--
break exits switch without fall-through (#96, #115, #483)
--FILE--
<?php
$i = 0;
switch (2) {
    case 1:
        $i = 1;
        break;
    case 2:
        $i = 2;
        break;
    default:
        $i = 99;
}
echo $i, "\n";
--EXPECT--
2
