<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Dedicated provider — slash-free data-set names so --filter works (#30010).
 * declare(ticks=1) + echo must fire per Zend ECHO cadence (not once per comma-group).
 */
final class DeclareTicksEchoCadenceVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'declare_ticks_echo_cadence.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/declare_ticks_echo_cadence.phpt',
            'declare_ticks_echo_cadence.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
