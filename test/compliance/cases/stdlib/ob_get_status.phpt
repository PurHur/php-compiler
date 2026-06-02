--TEST--
stdlib ob_get_status() — buffer metadata (issue #3647)
--FILE--
<?php
ob_start();
echo 'x';
$top = ob_get_status();
$all = ob_get_status(true);
$l = $top['level'];
$n = $top['name'];
$u = $top['buffer_used'];
$c = count($all);
$a0l = $all[0]['level'];
$a0u = $all[0]['buffer_used'];
ob_end_clean();
echo $l, ':', $n, ':', $u, "\n";
echo $c, ':', $a0l, ':', $a0u, "\n";

ob_start();
ob_start();
echo 'inner';
$top2 = ob_get_status();
$all2 = ob_get_status(true);
$t2l = $top2['level'];
$t2u = $top2['buffer_used'];
$c2 = count($all2);
$l0 = $all2[0]['level'];
$l1 = $all2[1]['level'];
$u1 = $all2[1]['buffer_used'];
ob_end_clean();
ob_end_clean();
echo $t2l, ':', $t2u, "\n";
echo $c2, ':', $l0, ':', $l1, ':', $u1, "\n";
echo function_exists('ob_get_status') ? '1' : '0', "\n";
--EXPECT--
0:default output handler:1
1:0:1
1:5
2:0:1:5
1
