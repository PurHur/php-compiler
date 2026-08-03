--TEST--
AOT: fdiv() INF/-INF/NAN via is_nan on boxed result (#27412)
--FILE--
<?php
echo fdiv(1.0, 0.0), "|", fdiv(-1.0, 0.0), "|";
$r = fdiv(0.0, 0.0);
echo (is_nan($r) ? "NAN" : $r), "\n";
--EXPECT--
INF|-INF|NAN
--EXPECT_EXIT--
0
