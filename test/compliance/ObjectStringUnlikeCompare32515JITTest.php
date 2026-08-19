<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: object vs string ordered compare / == (#32515 leftover of #32503).
 *
 * @group llvm
 */
final class ObjectStringUnlikeCompare32515JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_string_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_string_unlike_compare.phpt',
            'object_string_unlike_compare.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
