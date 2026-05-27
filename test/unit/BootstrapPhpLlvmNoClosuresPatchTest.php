<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: php-llvm vendor prelink bundles must not contain Expr_Closure (#1416).
 */
final class BootstrapPhpLlvmNoClosuresPatchTest extends TestCase
{
    public function testNoClosuresPatchIsWellFormed(): void
    {
        $patch = dirname(__DIR__, 2).'/patches/php-llvm-no-closures-array-map.patch';
        $this->assertFileExists($patch);
        $root = dirname(__DIR__, 2);
        $forward = shell_exec(
            'cd '.escapeshellarg($root)
            .' && git apply --check -p0 '.escapeshellarg($patch).' 2>&1'
        );
        $reverse = shell_exec(
            'cd '.escapeshellarg($root)
            .' && git apply --reverse --check -p0 '.escapeshellarg($patch).' 2>&1'
        );
        $this->assertTrue(
            false === strpos((string) $forward, 'corrupt patch')
            && false === strpos((string) $reverse, 'corrupt patch'),
            'php-llvm-no-closures-array-map.patch must be a valid unified diff'
        );
        $this->assertTrue(
            false === strpos((string) $forward, 'error:')
            || false === strpos((string) $reverse, 'error:'),
            "patch must apply forward or already be applied:\nforward: {$forward}\nreverse: {$reverse}"
        );
    }

    public function testContextAndBuilderUseForeachRefsAfterApplyPatches(): void
    {
        $root = dirname(__DIR__, 2);
        $context = $root.'/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php';
        $builder = $root.'/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php';
        $this->assertFileExists($context);
        $this->assertFileExists($builder);
        $contextBody = (string) file_get_contents($context);
        $builderBody = (string) file_get_contents($builder);
        $this->assertStringContainsString('$paramTypes = [];', $contextBody);
        $this->assertStringContainsString('$valueRefs = [];', $builderBody);
        $this->assertStringNotContainsString('function(Type $type)', $contextBody);
        $this->assertStringNotContainsString('function(Value $value)', $builderBody);
    }
}
