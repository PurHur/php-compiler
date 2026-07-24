--TEST--
Language: spaceship (<=>) numeric strings use zendi_smart_strcmp (#22848)
--FILE--
<?php
foreach ([["10", "2"], ["2", "10"], ["1e1", "10"], ["0", "00"]] as [$a, $b]) {
    echo var_export($a, true), "<=>", var_export($b, true), " => ", $a <=> $b, "\n";
}
echo 'a' <=> 'b', "\n";
echo "10" <=> 2, "\n";
--EXPECT--
'10'<=>'2' => 1
'2'<=>'10' => -1
'1e1'<=>'10' => 0
'0'<=>'00' => 0
-1
1
