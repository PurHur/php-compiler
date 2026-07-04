--TEST--
stdlib atan2() non-finite operands in nested var_export (#10070, ext/standard/math.c)
--FILE--
<?php
declare(strict_types=1);
echo 'inf_inf:', var_export(atan2(INF, INF), true), "\n";
echo 'neg_zero:', var_export(atan2(-0.0, -0.0), true), "\n";
echo 'nan_y:', var_export(atan2(NAN, 1.0), true), "\n";
--EXPECT--
inf_inf:0.7853981633974483
neg_zero:-3.141592653589793
nan_y:NAN
