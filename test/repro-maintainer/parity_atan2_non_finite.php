<?php

declare(strict_types=1);

echo 'inf_inf:', var_export(atan2(INF, INF), true), "\n";
echo 'neg_zero:', var_export(atan2(-0.0, -0.0), true), "\n";
echo 'nan_y:', var_export(atan2(NAN, 1.0), true), "\n";
