<?php
// #24009: AOT ??= / assign-from-?? must leave the named local usable (script-global __value__**).
$z = null;
$z ??= 'set';
echo $z, "\n";
$k = 'keep';
$k ??= 'other';
echo $k, "\n";
$t = null;
$t = $t ?? 'from-temp';
echo $t, "\n";
