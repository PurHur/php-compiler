<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for FILTER_VALIDATE_IP bracketed IPv6 rejection (#29063). */
final class FilterVarValidateIpBracketsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_validate_ip_brackets.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_validate_ip_brackets.phpt',
            'filter_var_validate_ip_brackets.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
