<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\ReadonlyFunctionRewriter;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.4 readonly function source rewrite (#17657). */
final class ReadonlyFunctionRewriterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsReadonlyFunction()) {
            $this->markTestSkipped('readonly function requires PHP_COMPILER_PROFILE=8.4');
        }
    }

    public function testRewritesReadonlyFunctionModifier(): void
    {
        $source = <<<'PHP'
<?php
readonly function ro(): int { return 42; }
PHP;
        $rewritten = ReadonlyFunctionRewriter::rewrite($source);
        self::assertStringNotContainsString('readonly function', $rewritten);
        self::assertStringContainsString('phpc-readonly-function', $rewritten);
        self::assertMatchesRegularExpression(
            '/\/\*\s*phpc-readonly-function\s*\*\/\s+function\s+ro/',
            $rewritten
        );
    }

    public function testReadonlyFunctionCompilesAndRuns(): void
    {
        $code = <<<'PHP'
<?php
readonly function ro(): int {
    return 42;
}
echo ro(), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'readonly_function.php');
        ob_start();
        $rt->run($block);
        self::assertSame("42\n", ob_get_clean());
    }
}
