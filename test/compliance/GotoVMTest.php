<?php

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/** VM compliance for goto / labels (issue #1228). */
class GotoVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'goto.phpt',
            'goto_try_finally_before_label.phpt',
            'goto_invalid_into_loop.phpt',
            'goto_invalid_into_switch.phpt',
            'goto_invalid_into_switch_eval.phpt',
            'goto_invalid_into_finally.phpt',
            'goto_invalid_out_of_finally.phpt',
            'break_outside_loop_fatal.phpt',
            'continue_outside_loop.phpt',
            'continue_switch_warning.phpt',
            'continue_switch_level_warning.phpt',
        ] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
