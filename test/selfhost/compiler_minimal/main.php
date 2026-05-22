<?php

declare(strict_types=1);

/**
 * Bundled self-host lint entry: minimal lib/ closure for Compiler.php (issue #212).
 * Literal require_once paths are discovered by LiteralIncludeDiscovery in bin/compile.php -l.
 */

require_once __DIR__.'/../../../lib/OpCode.php';
require_once __DIR__.'/../../../lib/Block.php';
require_once __DIR__.'/../../../lib/Frame.php';
require_once __DIR__.'/../../../lib/Func.php';
require_once __DIR__.'/../../../lib/Func/PHP.php';
require_once __DIR__.'/../../../lib/Runtime.php';
require_once __DIR__.'/../../../lib/Web/ConstStringFolder.php';
require_once __DIR__.'/../../../lib/Web/IncludePathResolver.php';
require_once __DIR__.'/../../../lib/Module.php';
require_once __DIR__.'/../../../lib/VM.php';
require_once __DIR__.'/../../../lib/Compiler.php';

echo "compiler_minimal bundle OK\n";
