<?php

$c = CAL_GREGORIAN;
echo 'var=', $c, "\n";
echo 'lit=', cal_to_jd(0, 6, 6, 2026), "\n";
echo 'const=', cal_to_jd(CAL_GREGORIAN, 6, 6, 2026), "\n";
echo 'varcall=', cal_to_jd($c, 6, 6, 2026), "\n";
