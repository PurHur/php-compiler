<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: constructor property promotion untyped/string/mixed (#32349).
 *
 * @group llvm
 */
final class CtorPromoUntyped32349JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ctor_promo_untyped_32349.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/ctor_promo_untyped_32349.phpt',
            'ctor_promo_untyped_32349.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
