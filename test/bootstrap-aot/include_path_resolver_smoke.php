<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: IncludePathResolver::resolve real LLVM lowering (#816).
 */

require_once __DIR__.'/../../lib/Web/IncludePathResolver.php';

use PHPCompiler\Web\IncludePathResolver;

$from = __DIR__.'/deploy_path_data/templates/marker.php';

$resolved = IncludePathResolver::resolve('marker.php', $from);
echo null !== $resolved && is_file($resolved) ? '1' : '0';
echo "\n";
echo null === IncludePathResolver::resolve('missing.php', $from) ? '1' : '0';
echo "\n";
echo null === IncludePathResolver::resolve('', $from) ? '1' : '0';
echo "\n";
