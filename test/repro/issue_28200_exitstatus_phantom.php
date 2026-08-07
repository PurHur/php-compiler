<?php

declare(strict_types=1);

/**
 * Repro for #28200 — ExitStatus phantom absent under PROFILE≥8.4.
 * php-src never ships ExitStatus; exit()/die() accept string|int only.
 */
echo 'enum_exists=', enum_exists('ExitStatus') ? 'true' : 'false', "\n";
exit(0);
