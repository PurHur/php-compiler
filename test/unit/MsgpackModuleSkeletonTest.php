<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * msgpack extension module skeleton — withheld until #6551 (#7032, #11986).
 *
 * @group msgpack_module_skeleton
 */
final class MsgpackModuleSkeletonTest extends TestCase
{
    public function test_msgpack_not_advertised_until_implemented(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('msgpack_pack');
echo (int) function_exists('msgpack_unpack');
echo (int) extension_loaded('msgpack');
PHP;
        $block = $runtime->parseAndCompile($code, 'msgpack_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('000', ob_get_clean());
    }

    public function test_msgpack_pack_class_not_registered_as_builtin(): void
    {
        $runtime = new Runtime();
        self::assertFalse(
            VmReflection::functionExists($runtime->vmContext, 'msgpack_pack')
        );
    }
}
