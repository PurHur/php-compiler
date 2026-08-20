<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: str_word_count Zend stub named params + Reflection (#23920).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrWordCountNamed23920VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_word_count_named_23920.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_word_count_named_23920.phpt',
            'str_word_count_named_23920.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
