<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * msgpack extension module — pack/unpack round-trip (#6551, ext/msgpack/msgpack.c).
 *
 * @group msgpack
 */
final class MsgpackModuleTest extends TestCase
{
    public function test_msgpack_registered_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
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
        self::assertSame('111', ob_get_clean());
    }

    public function test_msgpack_scalar_and_array_roundtrip(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello', 'd' => true, 'e' => null, 'f' => 1.5];
$packed = msgpack_pack($data);
$unpacked = msgpack_unpack($packed);
echo (int) is_string($packed);
echo (int) ($unpacked === $data);
echo msgpack_unpack("\xff") === false ? '1' : '0';
PHP;
        $block = $runtime->parseAndCompile($code, 'msgpack_roundtrip.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('110', ob_get_clean());
    }
}
