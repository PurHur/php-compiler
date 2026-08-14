<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: preg_replace_callback_array() excess argc → ArgumentCountError (#30966). */
final class PregReplaceCallbackArrayExcessArgc30966VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'preg_replace_callback_array_excess_argc_30966.phpt' => self::parsePHPT(
            __DIR__.'/cases/pcre/preg_replace_callback_array_excess_argc_30966.phpt',
            'preg_replace_callback_array_excess_argc_30966.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
