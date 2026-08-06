--TEST--
net_get_interfaces() unicast before up + lo-first iface order (#28140)
--SKIPIF--
<?php
if (!function_exists('net_get_interfaces')) {
    die('skip net_get_interfaces');
}
$i = @net_get_interfaces();
if (!is_array($i) || !isset($i['lo'])) {
    die('skip no lo interface');
}
?>
--FILE--
<?php
declare(strict_types=1);

$i = net_get_interfaces();
echo json_encode(array_keys($i['lo'])), "\n";

$names = array_keys($i);
$loPos = array_search('lo', $names, true);
$before = false;
foreach ($names as $idx => $name) {
    if ('lo' !== $name && $idx < $loPos) {
        $before = true;
        break;
    }
}
echo $before ? 'lo_not_first' : 'lo_first_ok', "\n";
?>
--EXPECT--
["unicast","up"]
lo_first_ok
