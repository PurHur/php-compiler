<?php

declare(strict_types=1);

$lc = localeconv();
echo 'decimal=', var_export($lc['decimal_point'] ?? null, true), "\n";
echo 'thousands=', var_export($lc['thousands_sep'] ?? null, true), "\n";
echo 'currency=', var_export($lc['currency_symbol'] ?? null, true), "\n";
