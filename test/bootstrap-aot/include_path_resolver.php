<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: IncludePathResolver::resolve (relative + absolute paths).
 */

require_once __DIR__.'/../../lib/Web/IncludePathResolver.php';

use PHPCompiler\Web\IncludePathResolver;

$from = 'test/bootstrap-aot/deploy_path_data/templates/marker.php';

echo (IncludePathResolver::resolve('', $from) === null ? '1' : '0')."\n";
echo (IncludePathResolver::resolve('marker.php', $from) !== null ? '1' : '0')."\n";
echo (IncludePathResolver::resolve('missing.php', $from) === null ? '1' : '0')."\n";
echo (IncludePathResolver::resolve('/etc/hosts', $from) !== null ? '1' : '0')."\n";
echo (IncludePathResolver::resolve('/nonexistent/path.php', $from) === null ? '1' : '0')."\n";
