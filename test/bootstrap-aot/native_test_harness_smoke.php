<?php

declare(strict_types=1);

/**
 * Native test harness entry — curated selfhost smoke fixture (#15599).
 *
 * SSOT compile/run target: compiler_smoke_standalone.php (invoked by script/bootstrap-native-test.sh).
 * This file documents the harness subset; assertions are stdout grep in the shell runner, not Zend PHPUnit.
 *
 * @see script/bootstrap-native-test.sh
 * @see test/bootstrap-aot/compiler_smoke_standalone.php
 */

// Harness metadata only — runner compiles compiler_smoke_standalone.php directly.
const BOOTSTRAP_NATIVE_TEST_HARNESS_FIXTURE = __DIR__.'/compiler_smoke_standalone.php';
