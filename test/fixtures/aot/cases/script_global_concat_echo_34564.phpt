--TEST--
AOT: encapsed echo after script-global .= then another .= must not SIGSEGV (#34564)
--FILE--
<?php
$g = '';
$g .= 'A';
$g .= 'B';
echo "g=$g\n";
$h = '';
$h .= 'B';
echo "h=$h\n";
--EXPECT--
g=AB
h=B
--EXPECT_EXIT--
0
