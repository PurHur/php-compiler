<?php

declare(strict_types=1);

/**
 * Bootstrap AOT lint: PHPCfg\Block::$children as hashtable property (self-host JIT).
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCfg\Block;

$block = new Block();
$block->children = $block->children;
$n = 0;
foreach ($block->children as $_child) {
    ++$n;
}

echo (string) $n;
