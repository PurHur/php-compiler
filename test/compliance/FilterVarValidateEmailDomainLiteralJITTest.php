<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for FILTER_VALIDATE_EMAIL domain literals (#29045). */
final class FilterVarValidateEmailDomainLiteralJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_validate_email_domain_literal.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_validate_email_domain_literal.phpt',
            'filter_var_validate_email_domain_literal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
