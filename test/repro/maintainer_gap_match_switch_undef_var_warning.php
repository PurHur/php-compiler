<?php
error_reporting(E_ALL);
$x = 1;
$result = match ($x) {
    $undefined => 'u',
    1 => 'one',
    default => 'd',
};
echo "match=$result\n";
switch ($x) {
    case $undefined2:
        echo "switch=u\n";
        break;
    case 1:
        echo "switch=one\n";
        break;
}
