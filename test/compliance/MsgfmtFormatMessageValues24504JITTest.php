<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: msgfmt_format_message Reflection + named $values (#24504).
 *
 * @group llvm
 * @group jit
 */
final class MsgfmtFormatMessageValues24504JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'msgfmt_format_message_values_24504_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/intl/msgfmt_format_message_values_24504_jit.phpt',
            'msgfmt_format_message_values_24504_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
