<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type\String_::implement always-on NestedJIT for ini/error_reporting and date/time
 * (#35614 / peer #35613 stdlib batch, #34848 IniRuntime, #34241 date/time, #35301 bitwise-not).
 */
final class TypeStringImplementLazyIniDateRuntimeShrinkTest extends TestCase
{
    public function testStringImplementDropsEagerIniDateAndBitwiseNot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('#35614', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function implementStrlen', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            'StringBitwiseNot::implement',
            'IniSet::implement',
            'IniGet::implement',
            'ErrorReporting::implement',
            'StringDateTime::implement',
            'StringStrftime::implement',
            'StringStrptime::implement',
            'StringStrtotime::implement',
            'StringGmgetdate::implement',
            'StringGmmktime::implement',
            'StringMktime::implement',
            'StringSyslog::implement',
            'StringLocaltime::implement',
            'StringMicrotime::implement',
            'StringGettimeofday::implement',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'Type\\String_::implement must not eagerly '.$forbidden.' (#35614)'
            );
        }

        $this->assertStringContainsString(
            'LOAD_TYPE_STANDALONE === $this->context->loadType',
            $body,
            'standalone still defers String_::implement early (#13571)'
        );
    }

    public function testIniCallSitesEnsureLinkBeforeLookup(): void
    {
        $jitIni = (string) file_get_contents(__DIR__.'/../../ext/standard/JitIni.php');
        $this->assertStringContainsString('#35614', $jitIni);
        foreach (['set', 'get', 'getCfgVar', 'restore'] as $method) {
            $fnPos = strpos($jitIni, "function {$method}(");
            $this->assertNotFalse($fnPos, "JitIni::{$method} must exist");
            $nextFn = strpos($jitIni, 'public static function ', $fnPos + 1);
            $chunk = false === $nextFn ? substr($jitIni, $fnPos) : substr($jitIni, $fnPos, $nextFn - $fnPos);
            $this->assertStringContainsString(
                'IniRuntime::ensureLinked',
                $chunk,
                "JitIni::{$method} must ensureLinked before lookup (#35614)"
            );
        }

        $err = (string) file_get_contents(__DIR__.'/../../ext/standard/JitErrorReporting.php');
        $this->assertStringContainsString('IniRuntime::ensureLinked', $err);
        $this->assertStringContainsString('#35614', $err);
    }

    public function testDateCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitDate.php' => 'StringDateTime::ensureLinked',
            'ext/standard/JitStrptime.php' => 'StringStrptime::ensureLinked',
            'ext/standard/JitStrtotime.php' => 'StringStrtotime::ensureLinked',
            'ext/standard/JitMktime.php' => 'StringMktime::ensureLinked',
            'ext/standard/JitGmmktime.php' => 'StringGmmktime::ensureLinked',
            'ext/standard/JitGmgetdate.php' => 'StringGmgetdate::ensureLinked',
            'ext/standard/JitLocaltime.php' => 'StringLocaltime::ensureLinked',
            'ext/standard/JitSyslog.php' => 'StringSyslog::ensureLinked',
            'ext/standard/JitGettimeofday.php' => 'StringGettimeofday::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $file = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $file, $rel.' must link before use (#35614)');
        }
        $date = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('StringStrftime::ensureLinked', $date);
        $this->assertStringContainsString('StringMicrotime::ensureLinked', $date);
    }

    public function testNoNewRuntimeCForLazyIniDateAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'ini_get.c',
            'ini_set.c',
            'mktime.c',
            'strftime.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35614 — PHP JIT bridges only'
            );
        }
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('RUNTIME_C_SOURCES = [', $linker);
        $this->assertStringNotContainsString('ini_get.c', $linker);
    }
}
