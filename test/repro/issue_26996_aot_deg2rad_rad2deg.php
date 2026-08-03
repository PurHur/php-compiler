<?php
// #26996 / #27006 — deg2rad/rad2deg NestedJIT leaf (avoid round(): AOT round is a separate defect)
echo deg2rad(180), "\n";
echo rad2deg(M_PI_2), "\n";
