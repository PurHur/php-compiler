<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #15627 */
final class GlobalsAcquireReferenceCompileTest extends TestCase
{
    public function testGlobalsReferenceAcquisitionRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ref = &$GLOBALS;
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot acquire reference to $GLOBALS');
        $runtime->parseAndCompile($code, 'globals_acquire_ref.php');
    }

    public function testGlobalsElementReferenceStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ref = &$GLOBALS['key'];
PHP;
        $block = $runtime->parseAndCompile($code, 'globals_dim_acquire_ref.php');
        $this->assertNotNull($block);
    }
}
