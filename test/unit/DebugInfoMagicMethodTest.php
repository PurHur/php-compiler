<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for __debugInfo() debug export (issue #3259). */
final class DebugInfoMagicMethodTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/language/debug_info.phpt';
        yield 'debug_info.phpt' => self::parsePHPT($path, 'debug_info.phpt');
        $path = __DIR__.'/../compliance/cases/language/debug_info_type_error.phpt';
        yield 'debug_info_type_error.phpt' => self::parsePHPT($path, 'debug_info_type_error.phpt');
        $path = __DIR__.'/../compliance/cases/language/debug_info_throw_fatal.phpt';
        yield 'debug_info_throw_fatal.phpt' => self::parsePHPT($path, 'debug_info_throw_fatal.phpt');
        $path = __DIR__.'/../compliance/cases/language/debug_info_throw_warning_frames.phpt';
        yield 'debug_info_throw_warning_frames.phpt' => self::parsePHPT($path, 'debug_info_throw_warning_frames.phpt');
    }
}
