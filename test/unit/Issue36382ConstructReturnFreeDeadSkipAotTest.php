<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Void __construct must not freeDeadVariables — property-escaped NEW temps (#36382).
 *
 * @group unit
 */
final class Issue36382ConstructReturnFreeDeadSkipAotTest extends TestCase
{
    public function testReturnVoidSkipsFreeDeadOnConstructFrame(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Concern/CompileBlockInternal.php');
        $pos = strpos($src, 'case OpCode::TYPE_RETURN_VOID:');
        $this->assertNotFalse($pos);
        $chunk = substr($src, $pos, 8000);
        $this->assertStringContainsString(
            'isJitConstructFrame($block)',
            $chunk,
            'TYPE_RETURN_VOID must skip freeDeadVariables on __construct (#36382)'
        );
        $this->assertMatchesRegularExpression(
            '/shouldFreeDeadVariablesBeforeBranch\(\)\s*&&\s*!\$this->isJitConstructFrame\(\$block\)/',
            $chunk
        );
    }
}
