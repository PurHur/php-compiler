<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5324 — (unset) cast compile-time fatal */
final class UnsetCastCompileFatalTest extends TestCase
{
    public function testUnsetCastFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('The (unset) cast is no longer supported');
        $runtime->parseAndCompile('<?php $b = (unset) $a;', 'unset_cast.php');
    }

    public function testOtherCastsStillCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php $b = (int) "5"; echo $b;', 'int_cast.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('5', ob_get_clean());
    }
}
