<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #31110
 *
 * php-src: Zend/zend_compile.c — Cannot declare {class,interface,trait}, name already in use.
 */
final class DuplicateClassLikeDeclareTest extends TestCase
{
    /**
     * @dataProvider classLikeSnippets
     */
    public function testDuplicateDeclareIsUncatchableCompileFatal(string $code, string $needle): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'dup_classlike.php');
        $this->assertNotNull($block);

        ob_start();
        try {
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected ScriptExit for duplicate class-like declare');
        } catch (\LogicException $e) {
            ob_end_clean();
            $this->fail('Must not throw host LogicException: '.$e->getMessage());
        } catch (ScriptExit $e) {
            $out = ob_get_clean();
            $this->assertSame(255, $e->status);
            $this->assertStringContainsString('before', (string) $out);
            $this->assertStringNotContainsString('after', (string) $out);
            $this->assertStringNotContainsString('CAUGHT', (string) $out);
            $this->assertStringNotContainsString('continued', (string) $out);
            $last = $runtime->vmContext->errors->getLastErrorVariable()->resolveIndirect();
            $this->assertSame(\PHPCompiler\VM\Variable::TYPE_ARRAY, $last->type);
            $msg = $last->toArray()->find('message');
            $this->assertNotNull($msg);
            $this->assertStringContainsString($needle, $msg->resolveIndirect()->toString());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function classLikeSnippets(): array
    {
        return [
            'class' => [
                <<<'PHP'
<?php
echo "before\n";
try {
    class C {}
    class C {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
PHP,
                'Cannot declare class C, because the name is already in use',
            ],
            'interface' => [
                <<<'PHP'
<?php
echo "before\n";
try {
    interface I {}
    interface I {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
PHP,
                'Cannot declare interface I, because the name is already in use',
            ],
            'trait' => [
                <<<'PHP'
<?php
echo "before\n";
try {
    trait T {}
    trait T {}
    echo "after\n";
} catch (Throwable $e) {
    echo 'CAUGHT:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "continued\n";
PHP,
                'Cannot declare trait T, because the name is already in use',
            ],
        ];
    }
}
