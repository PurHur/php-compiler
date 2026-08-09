<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/**
 * PROFILE-gated E_ALL constant (#27824) — Zend 8.4 drops E_STRICT (32767 → 30719).
 */
final class EAllProfileValueTest extends TestCase
{
    private function withProfile(string $profile, callable $fn): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE='.$profile);
        try {
            $fn();
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testEAllConstantAndStartupUnderProfile84(): void
    {
        $this->withProfile('8.4', static function (): void {
            self::assertSame(30719, ErrorReporter::eAll());
            self::assertSame(30719, Context::errorReportingConstant('E_ALL'));
            self::assertSame(30719, Context::errorReportingConstantExact('E_ALL'));
            self::assertSame(30719, ErrorReporter::defaultStartupReporting());
            self::assertSame(0, ErrorReporter::eAll() & ErrorReporter::E_STRICT);
        });
    }

    public function testEAllConstantAndStartupUnderProfile82(): void
    {
        $this->withProfile('8.2', static function (): void {
            self::assertSame(32767, ErrorReporter::eAll());
            self::assertSame(32767, Context::errorReportingConstant('E_ALL'));
            self::assertSame(32767, Context::errorReportingConstantExact('E_ALL'));
            // Explicit PROFILE matches Zend php.ini E_ALL (includes E_DEPRECATED) (#29195).
            self::assertSame(32767, ErrorReporter::defaultStartupReporting());
            self::assertSame(ErrorReporter::E_STRICT, ErrorReporter::eAll() & ErrorReporter::E_STRICT);
        });
    }

    public function testUnsetProfileKeepsComplianceStartupMask(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertSame(ErrorReporter::DEFAULT_STARTUP_REPORTING, ErrorReporter::defaultStartupReporting());
            self::assertSame(0, ErrorReporter::defaultStartupReporting() & ErrorReporter::E_DEPRECATED);
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testEAllConstantUnderProfile85Matches84(): void
    {
        $this->withProfile('8.5', static function (): void {
            self::assertSame(30719, ErrorReporter::eAll());
            self::assertSame(ErrorReporter::eAll(), ErrorReporter::defaultStartupReporting());
        });
    }
}
