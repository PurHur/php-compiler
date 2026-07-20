<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\igbinary\IgbinaryExtensionPolicy;
use PHPCompiler\ext\igbinary\VmIgbinary;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * igbinary extension — policy gate + VM round-trip (#6573).
 *
 * @group igbinary
 */
final class IgbinaryModuleTest extends TestCase
{
    public function test_igbinary_not_advertised_on_reference_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(IgbinaryExtensionPolicy::advertisesExtension());
            $runtime = new Runtime();
            foreach (['igbinary_serialize', 'igbinary_unserialize', 'igbinary_pack', 'igbinary_unpack'] as $fn) {
                self::assertFalse(VmReflection::functionExists($runtime->vmContext, $fn), $fn);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_igbinary_roundtrip_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(IgbinaryExtensionPolicy::advertisesExtension());
            $runtime = new Runtime();
            self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'igbinary_serialize'));

            $code = <<<'PHP'
<?php
$data = ['k' => 1, 'nested' => [true, 'x']];
$bin = igbinary_serialize($data);
echo igbinary_unserialize($bin) === $data ? "1" : "0";
PHP;
            $block = $runtime->parseAndCompile($code, 'igbinary_roundtrip.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('1', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_vm_igbinary_scalar_roundtrip(): void
    {
        $var = VmJson::import(['a' => 1, 'b' => 'z']);
        $bin = VmIgbinary::serialize($var);
        $decoded = VmIgbinary::unserialize($bin, null);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['a']);
        self::assertSame('z', $decoded['b']);
    }

    public function test_vm_igbinary_stdclass_roundtrip(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $o = new \stdClass();
            $o->a = 1;
            $o->b = 'x';
            $var = VmJson::importDecoded($o, false, (new Runtime())->vmContext);
            $bin = VmIgbinary::serialize($var);
            $decoded = VmIgbinary::unserialize($bin, null);
            self::assertInstanceOf(\stdClass::class, $decoded);
            self::assertSame(1, $decoded->a);
            self::assertSame('x', $decoded->b);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
