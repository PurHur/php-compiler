<?php
// #24009: `??=` / assign-from-?? must leave named locals usable under AOT (script-global load).
$z = null;
$z ??= 'set';
echo $z, "\n";
$k = 'keep';
$k ??= 'other';
echo $k, "\n";
