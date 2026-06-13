<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\ResponseContext;
use PHPUnit\Framework\TestCase;

/** VM rewrite var table for mod_rewrite API (#6031). */
final class ResponseContextRewriteVarsTest extends TestCase
{
    protected function tearDown(): void
    {
        ResponseContext::reset();
        parent::tearDown();
    }

    public function testAddReplaceAndReset(): void
    {
        ResponseContext::reset();
        $this->assertTrue(ResponseContext::addRewriteVar('NAME', 'value'));
        $this->assertSame(['NAME' => 'value'], ResponseContext::listRewriteVars());
        $this->assertTrue(ResponseContext::addRewriteVar('NAME', 'replaced'));
        $this->assertSame(['NAME' => 'replaced'], ResponseContext::listRewriteVars());
        $this->assertTrue(ResponseContext::resetRewriteVars());
        $this->assertSame([], ResponseContext::listRewriteVars());
    }

    public function testResetClearsOnResponseContextReset(): void
    {
        ResponseContext::addRewriteVar('a', '1');
        ResponseContext::reset();
        $this->assertSame([], ResponseContext::listRewriteVars());
    }
}
