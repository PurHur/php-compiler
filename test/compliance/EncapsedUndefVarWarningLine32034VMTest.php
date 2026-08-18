<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: encapsed/heredoc Undefined variable Warning cites user site (#32034).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class EncapsedUndefVarWarningLine32034VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'encapsed_undef_var_warning_line.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/encapsed_undef_var_warning_line.phpt',
            'encapsed_undef_var_warning_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
