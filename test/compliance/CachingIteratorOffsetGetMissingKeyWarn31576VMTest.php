<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: CachingIterator FULL_CACHE missing-key offsetGet Warning (#31576).
 */
final class CachingIteratorOffsetGetMissingKeyWarn31576VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cachingiterator_offsetget_missing_key_warn.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/cachingiterator_offsetget_missing_key_warn.phpt',
            'cachingiterator_offsetget_missing_key_warn.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
