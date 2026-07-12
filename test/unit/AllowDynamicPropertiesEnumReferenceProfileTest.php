<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

/** #[\AllowDynamicProperties] on enum reference profile gate (#17402). */
final class AllowDynamicPropertiesEnumReferenceProfileTest extends TestCase
{
    public function testRejectsAllowDynamicPropertiesOnEnumFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::rejectsAllowDynamicPropertiesOnEnum());
    }

    public function testRejectsAllowDynamicPropertiesOnEnumTrueWhenProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->assertTrue(CompilerVersion::rejectsAllowDynamicPropertiesOnEnum());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testRuntimeAcceptsMaintainerGapReproOnReferenceProfile(): void
    {
        if (CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
            $this->markTestSkipped('enum AllowDynamicProperties rejection enabled on PHP 8.5+ target');
        }
        $runtime = new \PHPCompiler\Runtime();
        $block = $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/issue-allow-dynamic-properties-enum.php'),
            'issue-allow-dynamic-properties-enum.php'
        );
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("compiled\n", $out);
    }

    public function testRuntimeRejectsMaintainerGapReproOnForwardProfile85(): void
    {
        if (!CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
            $prev = getenv('PHP_COMPILER_PROFILE');
            putenv('PHP_COMPILER_PROFILE=8.5');
            try {
                if (!CompilerVersion::rejectsAllowDynamicPropertiesOnEnum()) {
                    $this->markTestSkipped('enum AllowDynamicProperties rejection not enabled');
                }
            } finally {
                if (false === $prev) {
                    putenv('PHP_COMPILER_PROFILE');
                } else {
                    putenv('PHP_COMPILER_PROFILE='.$prev);
                }
            }
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new \PHPCompiler\Runtime();
            try {
                $runtime->parseAndCompile(
                    file_get_contents(dirname(__DIR__).'/repro/issue-allow-dynamic-properties-enum.php'),
                    'issue-allow-dynamic-properties-enum.php'
                );
                $this->fail('Expected compile failure');
            } catch (\CompileError $e) {
                $this->assertStringContainsString('Cannot apply #[AllowDynamicProperties] to enum Bad', $e->getMessage());
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
