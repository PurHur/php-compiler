<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #200: parse_str-style nested $_GET / $_POST population.
 */
final class SuperglobalsArrayParamsTest extends TestCase
{
    public function testNestedAndListQueryParameters(): void
    {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment(
            $runtime->vmContext,
            'tags[]=a&tags[]=b&user[name]=Ada',
            ''
        );

        $get = $runtime->vmContext->getSuperglobal('_GET');
        $this->assertNotNull($get);
        $table = $get->toArray();

        $tagsKey = new VMVariable();
        $tagsKey->string('tags');
        $this->assertTrue($table->offsetIsSet($tagsKey));
        $tags = $table->find('tags')->resolveIndirect()->toArray();

        $zero = new VMVariable();
        $zero->int(0);
        $one = new VMVariable();
        $one->int(1);
        $this->assertTrue($tags->offsetIsSet($zero));
        $this->assertTrue($tags->offsetIsSet($one));
        $this->assertSame('a', $tags->findIndex(0)->resolveIndirect()->toString());
        $this->assertSame('b', $tags->findIndex(1)->resolveIndirect()->toString());

        $userKey = new VMVariable();
        $userKey->string('user');
        $this->assertTrue($table->offsetIsSet($userKey));
        $user = $table->find('user')->resolveIndirect()->toArray();

        $nameKey = new VMVariable();
        $nameKey->string('name');
        $this->assertTrue($user->offsetIsSet($nameKey));
        $this->assertSame('Ada', $user->find('name')->resolveIndirect()->toString());
    }

    public function testNestedPostBody(): void
    {
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment(
            $runtime->vmContext,
            '',
            'items[]=x&items[]=y&meta[ok]=1'
        );

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $table = $post->toArray();

        $items = $table->find('items')->resolveIndirect()->toArray();
        $this->assertSame('x', $items->findIndex(0)->resolveIndirect()->toString());
        $this->assertSame('y', $items->findIndex(1)->resolveIndirect()->toString());

        $meta = $table->find('meta')->resolveIndirect()->toArray();
        $this->assertSame('1', $meta->find('ok')->resolveIndirect()->toString());
    }
}
