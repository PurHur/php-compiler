<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: ambiguous interface constants (eval / file / require) (#24699, #26672).
 */
final class InterfaceConstAmbiguityVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'interface_const_ambiguity_eval.phpt',
            'interface_const_ambiguity_eval_same_value.phpt',
            'interface_const_ambiguity_file.phpt',
            'interface_const_ambiguity_require.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
