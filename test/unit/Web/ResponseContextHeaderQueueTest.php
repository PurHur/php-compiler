<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\ResponseContext;
use PHPUnit\Framework\TestCase;

/** VM header queue gate mirrors JIT CGI detection (#4037, #4110). */
final class ResponseContextHeaderQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GATEWAY_INTERFACE');
        ResponseContext::reset();
        parent::tearDown();
    }

    public function testHeaderQueueDisabledWithoutGatewayInterfaceEnv(): void
    {
        putenv('GATEWAY_INTERFACE');
        ResponseContext::reset();
        ResponseContext::syncHeaderQueueFromEnvironment();
        $this->assertFalse(ResponseContext::isHeaderQueueEnabled());
        ResponseContext::addHeader('X: 1');
        $this->assertSame([], ResponseContext::listHeaders());
    }

    public function testHeaderQueueEnabledWhenGatewayInterfaceEnvSet(): void
    {
        putenv('GATEWAY_INTERFACE=CGI/1.1');
        ResponseContext::reset();
        ResponseContext::syncHeaderQueueFromEnvironment();
        $this->assertTrue(ResponseContext::isHeaderQueueEnabled());
        ResponseContext::addHeader('X-Test: 1');
        $this->assertSame(['X-Test: 1'], ResponseContext::listHeaders());
    }
}
