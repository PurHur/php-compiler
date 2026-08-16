<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: CachingIterator exclusive TOSTRING flags → ValueError (#31551).
 */
final class CachingIteratorMultiToStringFlags31551VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cachingiterator_multi_tostring_flags.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/cachingiterator_multi_tostring_flags.phpt',
            'cachingiterator_multi_tostring_flags.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
