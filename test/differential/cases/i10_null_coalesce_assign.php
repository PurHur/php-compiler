<?php
// #24009: `??=` leaves the variable empty under AOT — for null AND non-null subjects, so it is not
// a wrong-branch bug. FAILS AOT today by design; becomes a live guard when #24009 lands.
$z = null;
$z ??= 'set';
echo $z, "\n";
$k = 'keep';
$k ??= 'other';
echo $k, "\n";
