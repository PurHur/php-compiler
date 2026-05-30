<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** #[\SensitiveParameter] trace redaction (issue #3351). */
final class SensitiveParameterTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'sensitive_parameter_backtrace.phpt',
                'sensitive_parameter_trace_string.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/../compliance/cases/language/'.$file,
                $file
            );
        }
    }
}
