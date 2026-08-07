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
    public function test_msgpack_withheld_on_reference_profile(): void
    {
        self::assertFalse(CompilerVersion::supportsMsgpack());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['msgpack_pack', 'msgpack_unpack', 'msgpack_serialize', 'msgpack_unserialize'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('msgpack_pack');
echo (int) function_exists('msgpack_unpack');
echo (int) function_exists('msgpack_serialize');
echo (int) function_exists('msgpack_unserialize');
echo (int) extension_loaded('msgpack');
echo (int) class_exists('MessagePack', false);
echo (int) defined('MESSAGEPACK_OPT_PHPONLY');
PHP;
        $block = $runtime->parseAndCompile($code, 'msgpack_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000000', ob_get_clean());
    }

    public function test_msgpack_registered_and_extension_loaded_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsMsgpack());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;

            foreach (['msgpack_pack', 'msgpack_unpack', 'msgpack_serialize', 'msgpack_unserialize'] as $fn) {
                self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
            }

            $code = <<<'PHP'
<?php
echo (int) function_exists('msgpack_pack');
echo (int) function_exists('msgpack_unpack');
echo (int) function_exists('msgpack_serialize');
echo (int) function_exists('msgpack_unserialize');
echo (int) extension_loaded('msgpack');
echo (int) class_exists('MessagePack', false);
echo (int) class_exists('MessagePackUnpacker', false);
echo (int) defined('MESSAGEPACK_OPT_PHPONLY');
echo MESSAGEPACK_OPT_PHPONLY === -1001 ? '1' : '0';
PHP;
            $block = $runtime->parseAndCompile($code, 'msgpack_module.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('111111111', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_msgpack_scalar_and_array_roundtrip_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
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
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Serialize aliases + MessagePack::pack match procedural pack (#27872). */
    public function test_msgpack_serialize_aliases_and_oo_match_pack_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello', 'd' => true, 'e' => null, 'f' => 1.5];
$pack = msgpack_pack($data);
$ser = msgpack_serialize($data);
$mp = new MessagePack();
$oo = $mp->pack($data);
echo (int) ($pack === $ser);
echo (int) ($pack === $oo);
echo (int) (msgpack_unserialize($ser) === $data);
echo (int) ($mp->unpack($pack) === $data);
PHP;
            $block = $runtime->parseAndCompile($code, 'msgpack_serialize_oo.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('1111', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
