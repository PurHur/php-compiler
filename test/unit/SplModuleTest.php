<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** SPL module skeleton registration (issue #4769). */
final class SplModuleTest extends TestCase
{
    public function testSplBuiltinClassesExistOnVm(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'ArrayObject'));
        self::assertTrue(VmReflection::classExists($ctx, 'SplDoublyLinkedList'));
        self::assertFalse(VmReflection::classExists($ctx, 'SplQueue'));
        self::assertTrue(VmReflection::interfaceExists($ctx, 'SplObserver'));
        self::assertTrue(VmReflection::interfaceExists($ctx, 'SplSubject'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('ArrayObject', false);
echo (int) class_exists('SplDoublyLinkedList', false);
echo (int) class_exists('SplQueue', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'spl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('110', ob_get_clean());
    }
}
