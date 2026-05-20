<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

final class SuperglobalsRequestBodyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('REQUEST_BODY');
        parent::tearDown();
    }

    public function testReadRequestBodyFromEnvironment(): void
    {
        putenv('REQUEST_BODY={"ok":true}');
        $this->assertSame('{"ok":true}', Superglobals::readRequestBody());
    }

    public function testReadRequestBodyEmptyWhenUnset(): void
    {
        putenv('REQUEST_BODY');
        $this->assertSame('', Superglobals::readRequestBody());
    }
}
