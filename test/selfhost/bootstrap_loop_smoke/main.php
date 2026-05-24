<?php

declare(strict_types=1);

/**
 * M4 bootstrap-loop smoke bundle (scaffold — issue #1498).
 *
 * Intent: a native gen-1 binary compiles this compiler tree and produces gen-2;
 * gen-2 must rebuild the next revision without Zend PHP in the loop.
 *
 * Today: reuses the M3 HelloWorld link spine until a dedicated bootstrap-loop
 * bundle grows (bin/compile.php / src/cli.php path — #1467).
 *
 * Gate: php bin/compile.php -l test/selfhost/bootstrap_loop_smoke/main.php
 * Probe: make bootstrap-loop-probe  OR  ./script/bootstrap-loop-probe.sh [--dry-run]
 *
 * Tracker: https://github.com/PurHur/php-compiler/issues/1498
 */

require_once __DIR__.'/../compiler_helloworld_smoke/main.php';

echo "bootstrap_loop_smoke: M4 scaffold OK (gen-1→gen-2 loop not implemented)\n";
