<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: IncludePathResolver::resolve (string offset, is_file, realpath, ?string return).
 */

require_once __DIR__.'/../../lib/Web/IncludePathResolver.php';

use PHPCompiler\Web\IncludePathResolver;

$entry = __FILE__;
echo IncludePathResolver::resolve('relative.php', $entry) ?? 'null';
echo "\n";
echo IncludePathResolver::resolve('/etc/hosts', $entry) ?? 'null';
echo "\n";
