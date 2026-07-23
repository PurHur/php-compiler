<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * simdjson extension module — decode/is_valid MVP (#22530, PECL simdjson).
 *
 * @group simdjson
 */
final class SimdjsonModuleTest extends TestCase
{
    public function test_simdjson_withheld_on_reference_profile(): void
    {
        self::assertFalse(CompilerVersion::supportsSimdjson());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['simdjson_decode', 'simdjson_is_valid'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('simdjson_decode');
echo (int) function_exists('simdjson_is_valid');
echo (int) extension_loaded('simdjson');
PHP;
        $block = $runtime->parseAndCompile($code, 'simdjson_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('000', ob_get_clean());
    }

    public function test_simdjson_registered_and_decode_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsSimdjson());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;

            foreach (['simdjson_decode', 'simdjson_is_valid'] as $fn) {
                self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
            }

            $code = <<<'PHP'
<?php
echo (int) simdjson_is_valid('{"a":1}');
echo (int) (simdjson_decode('{"a":1}', true) === ['a' => 1]);
echo (int) function_exists('simdjson_decode');
echo (int) extension_loaded('simdjson');
try {
    simdjson_decode('{');
    echo '0';
} catch (SimdJsonException $e) {
    echo '1';
}
PHP;
            $block = $runtime->parseAndCompile($code, 'simdjson_roundtrip.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('11111', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
