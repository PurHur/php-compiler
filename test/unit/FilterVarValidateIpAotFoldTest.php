<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** FILTER_VALIDATE_IP compile-time fold must accept constInt(0) flags from dispatchConstFilter. */
final class FilterVarValidateIpAotFoldTest extends TestCase
{
    public function testValidateIpFoldChecksCompileTimeZeroFlags(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('compileTimeFlagsInt', $source);
        $this->assertMatchesRegularExpression(
            '/validateIp[\s\S]*?null !== \$flagsInt/',
            $source
        );
    }
}
