<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: exec/passthru/system Zend stub names + named result_code (#23625).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ExecPassthruSystemNamed23625VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exec_passthru_system_named_23625.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/exec_passthru_system_named_23625.phpt',
            'exec_passthru_system_named_23625.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
