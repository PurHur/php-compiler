--TEST--
language WeakMap — count() drops to 0 immediately after key unset (#19369)
--FILE--
<?php
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 42;
echo 'get=', $wm[$o], "\n";
echo 'count_alive=', count($wm), "\n";
$o = null;
echo 'count_after_unset=', count($wm), "\n";
$dead = 0;
foreach ($wm as $k => $v) {
    $dead++;
}
echo "foreach_after_unset=$dead\n";

// Second map: foreach must not keep the key alive after $k is unset (#19369).
$wm2 = new WeakMap();
$o2 = new stdClass();
$wm2[$o2] = 7;
$n = 0;
foreach ($wm2 as $k2 => $v2) {
    $n++;
}
echo "foreach_alive=$n\n";
$o2 = null;
unset($k2, $v2);
echo 'count_after_key_locals_cleared=', count($wm2), "\n";
--EXPECT--
get=42
count_alive=1
count_after_unset=0
foreach_after_unset=0
foreach_alive=1
count_after_key_locals_cleared=0
