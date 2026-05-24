<?php

declare(strict_types=1);

/**
 * M4 bootstrap-loop smoke bundle (issue #1498).
 *
 * Intent: native gen-1 (this bundle) compiles a minimal gen-2 target, then gen-2
 * rebuilds the next compiler revision without Zend PHP in the loop.
 *
 * Today: reuses the M3 HelloWorld link spine; gen-1 link + gen-2 Zend emit slice
 * is wired via script/bootstrap-loop-gen1-link.sh (native gen-2 emit blocked on M3).
 *
 * Gate: php bin/compile.php -l test/selfhost/bootstrap_loop_smoke/main.php
 * Compile driver lint: php bin/compile.php -l test/selfhost/bootstrap_loop_smoke/compile_driver.php
 * Gen-1 link: ./script/bootstrap-loop-gen1-link.sh
 * Probe: make bootstrap-loop-probe  OR  ./script/bootstrap-loop-probe.sh [--dry-run]
 *
 * Tracker: https://github.com/PurHur/php-compiler/issues/1498
 */

require_once __DIR__.'/../compiler_helloworld_smoke/main.php';
require_once __DIR__.'/../../bootstrap-aot/bootstrap_loop_compile_smoke.php';

echo "bootstrap_loop_smoke bundle OK\n";
