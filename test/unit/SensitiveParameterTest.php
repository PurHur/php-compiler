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
                'sensitive_parameter_print_backtrace.phpt',
                'sensitive_parameter_trace_string.phpt',
                'sensitive_parameter_value_json_var_export.phpt',
                'sensitive_parameter_exception_trace.phpt',
                'sensitive_parameter_get_trace_default.phpt',
                'exception_ignore_args.phpt',
                'exception_ignore_args_sensitive_wrap.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/../compliance/cases/language/'.$file,
                $file
            );
        }
    }
}
