<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: phpc_explode_find_delim must scopeLoweringToFunction (#33521 / #27211).
 *
 * Without the scope, SplFileObject::current/foreach AOT fails Module verify
 * (jit_find_* blocks land on the user function).
 *
 * @group llvm
 */
final class JitExplodeFindDelimScope33521Test extends TestCase
{
    public function testEnsureFindDelimUsesScopeLowering(): void
    {
        $root = dirname(__DIR__, 2);
        $src = file_get_contents($root.'/ext/standard/JitExplode.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('function ensureFindDelimFunction', $src);
        $fnPos = strpos($src, 'function ensureFindDelimFunction');
        $this->assertNotFalse($fnPos);
        $chunk = substr($src, $fnPos, 2200);
        $this->assertStringContainsString('scopeLoweringToFunction', $chunk);
        $this->assertStringContainsString('phpc_explode_find_delim', $chunk);
        $this->assertStringContainsString('VmStringCompare::findOffset', $chunk);
    }
}
