<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** #28412 — get_last_response_headers() absent; http_* remain on PROFILE≥8.4. */
final class Issue28412GetLastResponseHeadersPhantomTest extends TestCase
{
    public function testAliasAbsentOnDefaultAndForwardProfiles(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        foreach ([false, '8.4', '8.5'] as $profile) {
            if (false === $profile) {
                putenv('PHP_COMPILER_PROFILE');
                $label = 'default';
            } else {
                putenv('PHP_COMPILER_PROFILE='.$profile);
                $label = $profile;
            }
            $this->assertFalse(CompilerVersion::supportsGetLastResponseHeadersAlias(), $label);
            $this->assertFalse(CompilerVersion::advertisesGetLastResponseHeadersAlias(), $label);

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            $this->assertFalse(isset($ctx->functions['get_last_response_headers']), $label);
            $this->assertFalse(
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'get_last_response_headers'),
                $label
            );

            $expectHttp = CompilerVersion::supportsHttpLastResponseHeaders();
            $this->assertSame(
                $expectHttp,
                isset($ctx->functions['http_get_last_response_headers']),
                $label
            );
            $this->assertSame(
                $expectHttp,
                \PHPCompiler\ext\standard\VmReflection::functionExists($ctx, 'http_get_last_response_headers'),
                $label
            );
            $this->assertSame(
                $expectHttp,
                isset($ctx->functions['http_clear_last_response_headers']),
                $label
            );
        }
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
