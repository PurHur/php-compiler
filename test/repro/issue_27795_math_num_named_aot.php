<?php
/**
 * #27795 companion — AOT named $num (Reflection is VM/JIT; AOT dispatch separate).
 */
echo 'deg2rad=', (int) round(rad2deg(deg2rad(num: 180))), "\n";
echo 'rad2deg=', (int) round(rad2deg(num: M_PI)), "\n";
echo 'expm1=', (int) expm1(num: 0), "\n";
echo 'log1p=', (int) log1p(num: 0), "\n";
echo 'asinh=', (int) asinh(num: 0), "\n";
echo 'acosh=', (int) acosh(num: 1), "\n";
echo 'atanh=', (int) atanh(num: 0), "\n";
