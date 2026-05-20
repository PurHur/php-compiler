<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #52: application/json POST populates $_POST (VM).
 */
final class SuperglobalsJsonPostTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('REQUEST_METHOD');
        putenv('REQUEST_BODY');
        putenv('CONTENT_TYPE');
        putenv('HTTP_CONTENT_TYPE');
        parent::tearDown();
    }

    public function testJsonPostPopulatesPostAndRequest(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY={"name":"Ada","id":42}');
        putenv('CONTENT_TYPE=application/json');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertSame('Ada', $post->toArray()->find('name')->resolveIndirect()->toString());
        $this->assertSame(42, $post->toArray()->find('id')->resolveIndirect()->toInt());

        $request = $runtime->vmContext->getSuperglobal('_REQUEST');
        $this->assertNotNull($request);
        $this->assertSame('Ada', $request->toArray()->find('name')->resolveIndirect()->toString());
    }

    public function testInvalidJsonLeavesPostEmpty(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY={not json');
        putenv('CONTENT_TYPE=application/json');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertNull($post->toArray()->find('name'));
    }

    public function testFormPostStillUsesParseStr(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=name=Bob');
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertSame('Bob', $post->toArray()->find('name')->resolveIndirect()->toString());
    }
}
