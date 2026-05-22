<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/Block.php and lib/Compiler.php (php-types doc comment coercion).
 */
final class BlockAotLintTest extends TestCase
{
    /**
     * @dataProvider aotLintTargetProvider
     */
    public function testLibFileParseWithoutDocCommentTypeError(string $relativePath, bool $expectSuccess): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/'.$relativePath;
        $runtime = new Runtime(Runtime::MODE_AOT);
        try {
            $script = $runtime->parse((string) file_get_contents($path), $path);
            if ($expectSuccess) {
                $this->assertNotNull($script);
            }
        } catch (\TypeError $e) {
            $this->assertStringNotContainsString(
                'preg_match',
                $e->getMessage(),
                'php-types must coerce PhpParser\\Comment\\Doc before preg_match (php-types-doc-comment-string.patch)'
            );
            throw $e;
        } catch (\RuntimeException $e) {
            if ($expectSuccess) {
                throw $e;
            }
            // Compiler.php: past doc-comment coercion; further type-decl gaps are out of scope here.
            $this->assertStringNotContainsString('preg_match', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function aotLintTargetProvider(): iterable
    {
        yield 'Block' => ['lib/Block.php', true];
        yield 'Compiler' => ['lib/Compiler.php', false];
    }
}
