<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\BuiltinRegistry;
use PHPCompiler\ext\standard\VmHead;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** getallheaders() SAPI gating — phantom in CLI per php-src head.c (#11780). */
final class GetallheadersCliPhantomTest extends TestCase
{
    private string|false|null $savedRequestMethod = null;

    protected function setUp(): void
    {
        $this->savedRequestMethod = getenv('REQUEST_METHOD');
        if (false !== $this->savedRequestMethod) {
            putenv('REQUEST_METHOD');
            unset($_ENV['REQUEST_METHOD'], $_SERVER['REQUEST_METHOD']);
        }
    }

    protected function tearDown(): void
    {
        if (false !== $this->savedRequestMethod && null !== $this->savedRequestMethod) {
            putenv('REQUEST_METHOD='.$this->savedRequestMethod);
            $_ENV['REQUEST_METHOD'] = $this->savedRequestMethod;
            $_SERVER['REQUEST_METHOD'] = $this->savedRequestMethod;
        } else {
            putenv('REQUEST_METHOD');
            unset($_ENV['REQUEST_METHOD'], $_SERVER['REQUEST_METHOD']);
        }
        BuiltinRegistry::resetForTest();
    }

    public function testPlainCliDoesNotRegisterRequestHeaderFunctions(): void
    {
        $this->assertFalse(VmHead::registersRequestHeaderFunctions());
        $this->assertNotContains('getallheaders', BuiltinRegistry::sortedNames());
        $this->assertNotContains('apache_request_headers', BuiltinRegistry::sortedNames());

        $runtime = new Runtime();
        $this->assertArrayNotHasKey('getallheaders', $runtime->vmContext->functions);
        $this->assertArrayNotHasKey('apache_request_headers', $runtime->vmContext->functions);
    }

    public function testCgiRequestMethodRegistersRequestHeaderFunctions(): void
    {
        putenv('REQUEST_METHOD=GET');
        $_ENV['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assertTrue(VmHead::registersRequestHeaderFunctions());

        $runtime = new Runtime();
        $this->assertArrayHasKey('getallheaders', $runtime->vmContext->functions);
        $this->assertArrayHasKey('apache_request_headers', $runtime->vmContext->functions);
    }
}
