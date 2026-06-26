<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * igbinary extension module — withheld until #6573 (#7033, #11993).
 *
 * @group igbinary_module_skeleton
 */
final class IgbinaryModuleSkeletonTest extends TestCase
{
    public function test_igbinary_not_advertised_until_implemented(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['igbinary_serialize', 'igbinary_unserialize', 'igbinary_pack', 'igbinary_unpack'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('igbinary_serialize');
echo (int) function_exists('igbinary_unserialize');
echo (int) function_exists('igbinary_pack');
echo (int) function_exists('igbinary_unpack');
echo (int) extension_loaded('igbinary');
PHP;
        $block = $runtime->parseAndCompile($code, 'igbinary_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('00000', ob_get_clean());
    }

    public function test_igbinary_serialize_class_not_registered_as_builtin(): void
    {
        $runtime = new Runtime();
        self::assertFalse(
            VmReflection::functionExists($runtime->vmContext, 'igbinary_serialize')
        );
    }
}
