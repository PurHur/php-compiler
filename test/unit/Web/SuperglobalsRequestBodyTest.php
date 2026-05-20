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
        putenv('REQUEST_METHOD');
        putenv('CONTENT_TYPE');
        putenv('HTTP_CONTENT_TYPE');
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

    public function testPutJsonDoesNotPopulatePost(): void
    {
        putenv('REQUEST_METHOD=PUT');
        putenv('REQUEST_BODY={"ok":true}');
        putenv('CONTENT_TYPE=application/json');
        $this->assertFalse(Superglobals::shouldPopulatePostFromRequestBody());
    }

    public function testPutFormUrlencodedPopulatesPost(): void
    {
        putenv('REQUEST_METHOD=PUT');
        putenv('REQUEST_BODY=name=Ada');
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
        $this->assertTrue(Superglobals::shouldPopulatePostFromRequestBody());
    }

    public function testPostWithoutContentTypeStillPopulatesPost(): void
    {
        putenv('REQUEST_BODY=name=Ada');
        putenv('REQUEST_METHOD');
        putenv('CONTENT_TYPE');
        $this->assertTrue(Superglobals::shouldPopulatePostFromRequestBody());
    }
}
