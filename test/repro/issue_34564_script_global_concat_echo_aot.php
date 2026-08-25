<?php
// #34564: encapsed echo after script-global .= then another .= must not SIGSEGV under AOT
$g = '';
$g .= 'A';
$g .= 'B';
echo "g=$g\n";
$h = '';
$h .= 'B';
echo "h=$h\n";
