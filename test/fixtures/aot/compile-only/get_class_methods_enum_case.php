<?php
declare(strict_types=1);
// AOT compile-only (#8887): get_class_methods() on backed/unit enum cases.
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
