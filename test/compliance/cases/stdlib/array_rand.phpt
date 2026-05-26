--TEST--
stdlib array_rand() on packed lists (#2321)
--FILE--
<?php
$a = array('x', 'y', 'z');
$k = array_rand($a);
echo (is_int($k) && isset($a[$k])) ? 'one' : 'bad', "\n";
$keys = array_rand($a, 2);
echo (is_array($keys) && 2 === count($keys)) ? 'two' : 'bad', "\n";
foreach ($keys as $picked) {
    echo isset($a[$picked]) ? 'ok' : 'bad', "\n";
}
--EXPECT--
one
two
ok
ok
