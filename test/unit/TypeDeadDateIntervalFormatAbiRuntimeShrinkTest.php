<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_date_interval_format ABI shell from Builtin\Type (#33203).
 *
 * NestedJIT/AOT bridge stays DateIntervalFormatRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint date_interval_format.1 (#31894 / #32122).
 */
final class TypeDeadDateIntervalFormatAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnDateIntervalFormatAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33203', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_date_interval_format[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_date_interval_format (#33203)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_date_interval_format'",
            $type,
            'Builtin\\Type must not always-register __compiler_date_interval_format (#33203)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('DateIntervalFormatRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresDateIntervalFormatAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DateIntervalFormatRuntime.php');
        $this->assertStringContainsString('#33203', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementFormatBridge', $owner);
        $this->assertStringContainsString('__compiler_date_interval_format', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/DateIntervalFormatJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDateIntervalFormat.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDateIntervalFormat.php');
        $this->assertStringContainsString('#33203', $jit);
        $this->assertStringContainsString('DateIntervalFormatRuntime::ensureLinked', $jit);
    }

    public function testTypeInitializeLazyLinksDateIntervalFormatRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34243', $type);
        $this->assertStringNotContainsString(
            'DateIntervalFormatRuntime::ensureLinked($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly DateIntervalFormatRuntime::ensureLinked (#34243)'
        );
    }

    public function testNoNewRuntimeCForDateIntervalFormatAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/date_interval_format.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/date_interval_format.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_date_interval_format.c');
    }
}
