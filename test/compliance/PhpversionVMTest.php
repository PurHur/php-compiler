<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM compliance for phpversion/php_sapi_name/php_uname (#3174). */
final class PhpversionVMTest extends ComplianceTestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public function provideCases(): iterable
    {
        yield 'phpversion.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/phpversion.phpt',
            'phpversion.phpt'
        );
    }
}
