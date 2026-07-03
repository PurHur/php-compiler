<?php

declare(strict_types=1);

/**
 * Gen-N changed-sources smoke bundle (#15598).
 *
 * Bundles lib/OpCode.php (fast compile) plus a mutable marker.php. The probe patches both
 * marker.php and lib/OpCode.php to prove gen-2 emits working-tree sources, not stale
 * prelinked/bootstrap-gen0/ bytes (#8710). Same workflow applies to lib/Compiler.php edits.
 *
 * Gate: php bin/compile.php -l test/selfhost/changed_sources_smoke/main.php
 * Probe: make bootstrap-changed-sources-probe
 */

require_once __DIR__.'/marker.php';
require_once __DIR__.'/../../../lib/OpCode.php';

echo changed_sources_smoke_marker(), "\n";
echo "changed_sources_smoke bundle OK\n";
