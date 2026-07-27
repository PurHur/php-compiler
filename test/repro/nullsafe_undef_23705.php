<?php
error_reporting(E_ALL);
$o = new stdClass();
echo "direct:\n"; $x = $o->missing;
echo "nullsafe:\n"; $y = $o?->missing;
echo "y="; var_export($y); echo "\n";
$n = null;
echo "null-recv:\n"; $z = $n?->missing;
echo "z="; var_export($z); echo "\n";
