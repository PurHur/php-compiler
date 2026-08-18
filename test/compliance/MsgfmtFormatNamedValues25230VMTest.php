<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: MessageFormatter::format Reflection + named $values (#25230). */
final class MsgfmtFormatNamedValues25230VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'msgfmt_format_named_values_25230.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/intl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
