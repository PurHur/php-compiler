<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for settype() on backed enum cases (#8787). */
final class SettypeBackedEnumJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'settype_backed_enum_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_backed_enum_jit.phpt',
            'settype_backed_enum_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
