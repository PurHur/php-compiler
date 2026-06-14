<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_headers() must not delegate to host PHP get_headers() (#3309, php-in-php). */
final class VmGetHeadersRuntimeShrinkTest extends TestCase
{
    public function testGetHeadersBuiltinUsesNativeFetch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/get_headers.php');
        $this->assertStringContainsString('VmHttpFetchNative::fetchHeaders', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\get_headers\\s*\\(/', $source);
    }

    public function testVmHttpFetchNativeExposesFetchHeaders(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHttpFetchNative.php');
        $this->assertStringContainsString('fetchHeaders(string $url)', $source);
        $this->assertStringContainsString("request(\$url, 'HEAD')", $source);
    }
}
