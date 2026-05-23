<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: ConstStringFolder literalStringValue via fold() (real LLVM lowering).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Operand;
use PHPCompiler\Web\ConstStringFolder;

echo ConstStringFolder::fold(new Operand\Literal('templates')) === 'templates' ? '1' : '0';

$path = __DIR__.'/deploy_path_data/templates/marker.php';
$resolved = realpath($path);
echo is_string($resolved) && is_dir(dirname($resolved)) ? '1' : '0';
