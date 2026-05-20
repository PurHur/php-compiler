<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #291: PUT/PATCH/DELETE bodies and $_POST population policy.
 */
final class SuperglobalsPutBodyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('REQUEST_METHOD');
        putenv('REQUEST_BODY');
        putenv('CONTENT_TYPE');
        putenv('HTTP_CONTENT_TYPE');
        parent::tearDown();
    }

    public function testShouldPopulatePostForPutWithFormContentType(): void
    {
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
        $this->assertTrue(
            Superglobals::shouldPopulatePost('PUT', 'a=1')
        );
    }

    public function testShouldNotPopulatePostForPutJsonWithoutFormType(): void
    {
        putenv('CONTENT_TYPE=application/json');
        $this->assertFalse(
            Superglobals::shouldPopulatePost('PUT', '{"a":1}')
        );
    }

    public function testPutJsonBodyAvailableViaPhpInput(): void
    {
        putenv('REQUEST_METHOD=PUT');
        putenv('REQUEST_BODY={"ok":true}');
        putenv('CONTENT_TYPE=application/json');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $this->assertSame('{"ok":true}', Superglobals::readRequestBody());
        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertNull($post->toArray()->find('ok'));
    }

    public function testPutFormPopulatesPost(): void
    {
        putenv('REQUEST_METHOD=PUT');
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', 'name=Bob');

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertSame('Bob', $post->toArray()->find('name')->resolveIndirect()->toString());
    }
}
