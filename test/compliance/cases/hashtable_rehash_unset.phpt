--TEST--
VM hashtable rehash after unset keeps remaining string keys (#1761)
--FILE--
<?php
$a = [
    'k0' => '0',
    'k1' => '1',
    'k2' => '2',
    'k3' => '3',
    'k4' => '4',
    'k5' => '5',
    'k6' => '6',
    'k7' => '7',
    'k8' => '8',
    'k9' => '9',
];
unset($a['k1'], $a['k4']);
echo $a['k0'], $a['k2'], $a['k9'], "\n";
--EXPECT--
029
