--TEST--
AOT: scandir() returns entry array not false (#27236)
--FILE--
<?php
$s = scandir('.');
if (!is_array($s)) {
    echo 'NOTARRAY:'.var_export($s, true), "\n";
    exit(0);
}
echo in_array('.', $s, true) && in_array('..', $s, true) && count($s) > 2 ? 'ok' : 'bad';
echo "\n";
--EXPECT--
ok
