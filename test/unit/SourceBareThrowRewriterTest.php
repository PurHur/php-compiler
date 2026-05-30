<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class SourceBareThrowRewriterTest extends TestCase
{
    public function testRewritesBareThrowToNullThrowAndRecordsLine(): void
    {
        $source = <<<'PHP'
<?php
try {
    throw;
} catch (E $e) {
    throw ;
}
PHP;
        [$rewritten, $lines] = SourceBareThrowRewriter::rewrite($source);

        self::assertStringContainsString('throw null', $rewritten);
        self::assertArrayHasKey(3, $lines);
        self::assertArrayHasKey(5, $lines);
    }

    public function testLeavesThrowWithExpressionUntouched(): void
    {
        $source = '<?php throw new E();';
        [$rewritten, $lines] = SourceBareThrowRewriter::rewrite($source);

        self::assertSame($source, $rewritten);
        self::assertSame([], $lines);
    }
}
