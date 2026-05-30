<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for eval() (issue #3358). */
final class EvalBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'eval_basic.phpt',
            'eval_parse_error.phpt',
            'eval_parse_error_file.phpt',
            'eval_return_value.phpt',
        ] as $file) {
            $path = __DIR__.'/../compliance/cases/language/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }
}
