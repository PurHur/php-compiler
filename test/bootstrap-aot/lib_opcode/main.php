<?php

declare(strict_types=1);

/**
 * Phase D: link namespaced lib/OpCode.php (issue #540).
 */

namespace PHPCompiler;

require_once __DIR__.'/../../../lib/OpCode.php';

echo (string) OpCode::TYPE_ECHO."\n";
echo (string) OpCode::TYPE_ASSIGN."\n";
