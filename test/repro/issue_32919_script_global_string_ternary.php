<?php
// AOT: {main} string locals must be truthy in ?: / empty() (#32919).
$n = 'x';
echo ($n ? $n : 'z'), "\n";
echo ($n ? 'x' : 'z'), "\n";
echo empty($n) ? 'E' : 'N', "\n";
$z = '0';
echo ($z ? 'T' : 'F'), "\n";
