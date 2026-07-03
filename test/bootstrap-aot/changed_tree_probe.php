<?php

declare(strict_types=1);

/**
 * Gen-N changed-source probe fixture (#15598).
 *
 * Sentinel comment below is patched by script/bootstrap-changed-tree-probe.sh
 * (or PHP_COMPILER_BOOTSTRAP_CHANGED_TREE_MARKER selects the suffix).
 */

// CHANGED_TREE_PROBE_MARKER: probe-baseline
const CHANGED_TREE_PROBE_GREETING = 'changed-tree probe: probe-baseline';

echo CHANGED_TREE_PROBE_GREETING, "\n";
