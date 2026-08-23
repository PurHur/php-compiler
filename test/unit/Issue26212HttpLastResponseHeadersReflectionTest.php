<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * PHP 8.4 http_get/clear_last_response_headers Reflection returns (#26212).
 *
 * @see php-src ext/standard/http.stub.php
 */
final class Issue26212HttpLastResponseHeadersReflectionTest extends TestCase
{
    public function testStubReturnLabelsUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            // Rebuild profile-sensitive CompilerVersion caches if any — labels read live profile.
            $this->assertSame(
                '?array',
                BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('http_get_last_response_headers')
            );
            $this->assertSame(
                'void',
                BuiltinInternalArgInfo::stubReturnTypeLabelForFunction('http_clear_last_response_headers')
            );
            $this->assertSame(
                '?array',
                BuiltinInternalArgInfo::returnTypeLabelForFunction('http_get_last_response_headers')
            );
            $this->assertSame(
                'void',
                BuiltinInternalArgInfo::returnTypeLabelForFunction('http_clear_last_response_headers')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmReflectionMatchesStubUnderProfile84(): void
    {
        if (!CompilerVersion::supportsHttpLastResponseHeaders()
            && '8.4' !== getenv('PHP_COMPILER_PROFILE')
        ) {
            // Force 8.4 for this subprocess via docker/phpunit env when default is 8.2.
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_26212_http_last_response_headers_reflection.php';
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString(
            'http_get_last_response_headers ret=?array',
            $joined
        );
        $this->assertStringContainsString(
            'http_clear_last_response_headers ret=void',
            $joined
        );
        $this->assertStringContainsString('NULL', $joined);
        $this->assertStringContainsString('cleared', $joined);
    }
}
