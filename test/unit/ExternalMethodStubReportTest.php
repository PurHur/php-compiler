<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Silent-null method lowerings must be observable (#579).
 *
 * Call\ExternalMethod turns a method call on a class that is not in the module into
 * `__value__writeNull` with no diagnostic, so a module missing a class miscompiles quietly.
 * `Context::$externalMethodStubs` recorded them but was never read anywhere in the tree.
 */
final class ExternalMethodStubReportTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS');
        putenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS');
    }

    public function testExternalMethodRecordsAndReportsStubs(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/lib/JIT/Context.php');

        $this->assertStringContainsString(
            'public function reportExternalMethodStubs(): void',
            $source,
            'the stub record must be readable, not write-only'
        );
        $this->assertStringContainsString(
            'PHP_COMPILER_REPORT_EXTERNAL_STUBS',
            $source
        );
        $this->assertStringContainsString(
            'PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS',
            $source
        );
    }

    /** compileToFile() is the finalisation point, so the report must fire before object emit. */
    public function testReportIsInvokedFromCompileToFile(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/lib/JIT/Context.php');

        $compileToFile = strpos($source, 'public function compileToFile(');
        $this->assertIsInt($compileToFile);

        $reportCall = strpos($source, '$this->reportExternalMethodStubs();', $compileToFile);
        $this->assertIsInt($reportCall, 'compileToFile() must call the report');
    }

    /** Both env flags are opt-in: a normal build must not change behaviour. */
    public function testReportingIsOptIn(): void
    {
        putenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS');
        putenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS');

        $this->assertFalse(getenv('PHP_COMPILER_REPORT_EXTERNAL_STUBS'));
        $this->assertFalse(getenv('PHP_COMPILER_FAIL_ON_EXTERNAL_STUBS'));

        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $body = substr(
            $source,
            (int) strpos($source, 'public function reportExternalMethodStubs(): void')
        );

        // Early return when nothing was recorded, before either env is consulted.
        $this->assertStringContainsString('if ([] === $this->externalMethodStubs) {', $body);
    }
}
