<?php

declare(strict_types=1);

/**
 * Phase D: link namespaced lib/OpCode.php (issue #540).
 * Bundles lib/OpCode.php via literal require_once; stdout proves the unit linked.
 */

namespace PHPCompiler;

require_once __DIR__.'/../../../lib/OpCode.php';

echo "ok\n";
