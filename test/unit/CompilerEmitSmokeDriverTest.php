<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @see Compiler::compileEmitSmoke — production drivers with user functions (#2633) */
final class CompilerEmitSmokeDriverTest extends TestCase
{
    public function testCompileEmitSmokeUsesFullCompileWhenScriptHasFunctions(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/vendor/autoload.php';

        $runtime = new Runtime(Runtime::MODE_AOT);
        $path = $root.'/bin/compile.php';
        $script = $runtime->parse((string) file_get_contents($path), $path);
        $this->assertNotEmpty($script->functions);

        $emitBlock = $runtime->compiler->compileEmitSmoke($script);
        $fullBlock = $runtime->compiler->compile($script);

        $this->assertNotNull($emitBlock);
        $this->assertNotNull($fullBlock);
        $this->assertSame(count($fullBlock->opCodes), count($emitBlock->opCodes));
    }
}
