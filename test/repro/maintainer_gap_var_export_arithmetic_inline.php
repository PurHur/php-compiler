<?php

declare(strict_types=1);

// Maintainer repro: var_export(arithmetic_expr, true) must export expression not return flag (#17210).
echo var_export(1.0 + 0.0, true), "\n";
echo var_export(INF * 0, true), "\n";
