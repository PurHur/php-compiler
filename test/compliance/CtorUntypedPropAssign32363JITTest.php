<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: untyped $this->prop assign in constructor (#32363).
 *
 * @group llvm
 */
final class CtorUntypedPropAssign32363JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ctor_untyped_prop_assign_32363.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/ctor_untyped_prop_assign_32363.phpt',
            'ctor_untyped_prop_assign_32363.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
