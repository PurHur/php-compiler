<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: serialize omits virtual hooks / emits mangled backing (#28184). */
final class SerializePropertyHooksJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stdlib/serialize_property_hooks' => self::parsePHPT(
            __DIR__.'/cases/stdlib/serialize_property_hooks.phpt',
            'serialize_property_hooks.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
