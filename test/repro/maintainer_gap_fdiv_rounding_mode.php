<?php
// Issue #11730 — PHP 8.4 PHP_ROUND_CEILING/FLOOR/TOWARD_ZERO/AWAY_FROM_ZERO constants.
echo 'has_ceiling_const='.(defined('PHP_ROUND_CEILING') ? 'true' : 'false'), "\n";
echo 'has_floor_const='.(defined('PHP_ROUND_FLOOR') ? 'true' : 'false'), "\n";
echo 'has_toward_zero_const='.(defined('PHP_ROUND_TOWARD_ZERO') ? 'true' : 'false'), "\n";
echo 'has_away_from_zero_const='.(defined('PHP_ROUND_AWAY_FROM_ZERO') ? 'true' : 'false'), "\n";
echo 'ceiling_val=', defined('PHP_ROUND_CEILING') ? PHP_ROUND_CEILING : 'undef', "\n";
echo 'round_ceiling=', round(2.5, 0, PHP_ROUND_CEILING), "\n";
echo 'round_floor=', round(-2.5, 0, PHP_ROUND_FLOOR), "\n";
