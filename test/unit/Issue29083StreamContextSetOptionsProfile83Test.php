<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stream_context_set_options advertised on PROFILE=8.3 — php-src since 8.3.0 (#29083).
 */
final class Issue29083StreamContextSetOptionsProfile83Test extends TestCase
{
    public function testProfile83AdvertisesAndRoundTrips(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsStreamContextSetOptions());
            $this->assertTrue(CompilerVersion::advertisesStreamContextSetOptions());
            $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('stream_context_set_options'));

            $runtime = new Runtime();
            $this->assertTrue(isset($runtime->vmContext->functions['stream_context_set_options']));

            $out = $this->runVmRepro();
            $this->assertStringContainsString("exists=1\n", $out);
            $this->assertStringContainsString("set=1\n", $out);
            $this->assertStringContainsString("method=GET\n", $out);
            $this->assertStringContainsString("timeout=1\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testReferenceProfileStillWithholds(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsStreamContextSetOptions());
            $this->assertFalse(CompilerVersion::advertisesStreamContextSetOptions());
            $this->assertFalse(BuiltinIntrospectionPolicy::functionIsAdvertised('stream_context_set_options'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    private function runVmRepro(): string
    {
        $php = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg(__DIR__.'/../repro/issue_29083_stream_context_set_options_profile83.php');
        $cmd = 'PHP_COMPILER_PROFILE=8.3 '.$php.' '.escapeshellarg(__DIR__.'/../../bin/vm.php').' '.$script.' 2>&1';
        $out = shell_exec($cmd);

        return \is_string($out) ? $out : '';
    }
}
