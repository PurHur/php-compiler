<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: array_push / count on boxed array from concat include-path lists (Compiler.php pattern).
 */

class PathBlock
{
    /** @var array<int, string> */
    public $literalIncludePaths = [];
}

$block = new PathBlock();
$base = 'test/bootstrap-aot';
$block->literalIncludePaths[] = $base.'/marker.php';
echo count($block->literalIncludePaths);
array_push($block->literalIncludePaths, $base.'/deploy_path_include.php');
echo count($block->literalIncludePaths);
