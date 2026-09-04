<?php
// deg2rad()/rad2deg() via inline fmul must match Zend (#36386).
// @differential-repeat: 3
echo deg2rad(0.0), "\n";
echo deg2rad(180.0), "\n";
echo deg2rad(90.0), "\n";
echo deg2rad(-45.0), "\n";
echo rad2deg(0.0), "\n";
echo rad2deg(M_PI), "\n";
echo rad2deg(M_PI_2), "\n";
echo rad2deg(-M_PI_4), "\n";
$s = 0.0;
for ($i = 0; $i < 5; ++$i) {
    $s += deg2rad(180.0) + rad2deg(M_PI);
}
echo $s, "\n";
