--TEST--
AOT: (object) packed native array is stdClass — convert_to_object IS_ARRAY (#32468)
--FILE--
<?php
$o = (object) [1, 2];
echo get_class($o), "\n";
echo $o->{'0'}, "\n";
echo $o->{'1'}, "\n";
?>
--EXPECT--
stdClass
1
2
