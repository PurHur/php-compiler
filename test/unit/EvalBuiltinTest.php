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
            'eval_magic_consts.phpt',
            'eval_parse_error.phpt',
            'eval_parse_error_file.phpt',
            'eval_parseerror_unclosed_brace.phpt',
            'eval_typed_class_const_reject_catchable.phpt',
            'eval_return_value.phpt',
            'eval_this_scope.phpt',
        ] as $file) {
            $path = __DIR__.'/../compliance/cases/language/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }
}
