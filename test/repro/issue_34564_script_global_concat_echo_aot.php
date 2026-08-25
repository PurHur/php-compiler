<?php
// #34564 — echo encapsed script-global after .= then another local .= must not SIGSEGV
$g = '';
$g .= 'A';
$g .= 'B';
echo "g=$g\n";
$h = '';
$h .= 'B';
echo "h=$h\n";
