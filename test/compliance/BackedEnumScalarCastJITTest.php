<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance: backed enum scalar casts mirror VM php-src semantics (#6961). */
final class BackedEnumScalarCastJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'backed_enum_scalar_cast.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/backed_enum_scalar_cast.phpt',
            'backed_enum_scalar_cast.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
