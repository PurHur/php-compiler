<?php

declare(strict_types=1);

$lc = localeconv();
var_dump($lc['int_frac_digits']);
var_dump($lc['grouping']);
var_dump($lc['mon_grouping']);
var_dump(is_array($lc['grouping']));
