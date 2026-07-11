<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * random extension module skeleton registration (issue #7102).
 *
 * @group random_module_skeleton
 */
final class RandomModuleSkeletonTest extends TestCase
{
    public function test_random_module_skeleton_classes_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach ([
            'Random\\Randomizer',
            'Random\\Engine\\Mt19937',
            'Random\\Engine\\Secure',
            'Random\\Engine\\Xoshiro256StarStar',
            'Random\\Engine\\PcgOneseq128XslRr64',
            'Random\\RandomException',
            'Random\\RandomError',
            'Random\\BrokenRandomEngineError',
        ] as $class) {
            self::assertTrue(VmReflection::classExists($ctx, $class), $class);
        }

        $code = <<<'PHP'
<?php
echo (int) class_exists('Random\Randomizer');
echo (int) class_exists('Random\Engine\Mt19937');
echo (int) class_exists('Random\RandomException');
echo (int) extension_loaded('random');
PHP;
        $block = $runtime->parseAndCompile($code, 'random_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1111', ob_get_clean());
    }
}
