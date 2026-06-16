--TEST--
Stdlib: get_class_methods() on backed enum case — lists cases/from/tryFrom (#5614)
--FILE--
<?php
enum Color: string {
    case Red = 'red';
    case Blue = 'blue';
}
enum Status {
    case Active;
    case Done;
}
$backed = get_class_methods(Color::Red);
$unit = get_class_methods(Status::Active);
echo count($backed), "\n";
echo in_array('cases', $backed, true) ? '1' : '0';
echo in_array('from', $backed, true) ? '1' : '0';
echo in_array('tryFrom', $backed, true) ? '1' : '0';
echo count($unit), "\n";
echo in_array('cases', $unit, true) ? '1' : '0';
echo in_array('from', $unit, true) ? '1' : '0';
echo "\n";
--EXPECT--
3
1111
10
