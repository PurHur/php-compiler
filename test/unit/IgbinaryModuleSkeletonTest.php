<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\igbinary\IgbinaryExtensionPolicy;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * igbinary extension module — withheld on reference profile (#6573, #11993, #21463).
 *
 * @group igbinary_module_skeleton
 */
final class IgbinaryModuleSkeletonTest extends TestCase
{
    public function test_igbinary_not_advertised_on_reference_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(IgbinaryExtensionPolicy::advertisesExtension());
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
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_igbinary_serialize_advertised_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(IgbinaryExtensionPolicy::advertisesExtension());
            $runtime = new Runtime();
            self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'igbinary_serialize'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
