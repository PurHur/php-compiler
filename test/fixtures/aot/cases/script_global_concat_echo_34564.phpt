--TEST--
AOT: encapsed echo after script-global .= then another .= (#34564)
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
