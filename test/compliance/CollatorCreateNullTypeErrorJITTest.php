<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: collator_create / Collator::create / __construct(null) TypeError (#29933). */
final class CollatorCreateNullTypeErrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'collator_create_null_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/collator_create_null_typeerror_jit.phpt',
            'collator_create_null_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
