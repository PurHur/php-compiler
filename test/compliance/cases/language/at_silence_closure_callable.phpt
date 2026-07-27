--TEST--
@ silence does not break inline Closure callable validation (#23730)
--FILE--
<?php
@strlen(null);
set_error_handler(function ($no, $str) { echo "handler\n"; return true; });
trigger_error("t", E_USER_WARNING);

@strlen(null);
$a = [3, 1, 2];
usort($a, function ($x, $y) { return $x - $y; });
echo implode(',', $a) . "\n";

@strlen(null);
$r = array_map(function ($x) { return $x * 2; }, [1, 2, 3]);
echo implode(',', $r) . "\n";
--EXPECT--
handler
handler
1,2,3
handler
2,4,6
