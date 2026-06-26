<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Web;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\SuperglobalRefreshJitHelper;
use PHPUnit\Framework\TestCase;

/** SuperglobalRefreshJitHelper mirrors VM Superglobals CGI refresh (#9907). */
final class SuperglobalRefreshJitHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('QUERY_STRING');
        putenv('REQUEST_METHOD');
        putenv('HTTP_COOKIE');
        putenv('REQUEST_BODY');
        parent::tearDown();
    }

    public function testBuildGetTableFromQueryString(): void
    {
        putenv('QUERY_STRING=name=Alice');
        $get = SuperglobalRefreshJitHelper::buildGetTable();
        $name = $get->find('name');
        $this->assertNotNull($name);
        $this->assertSame('Alice', $name->resolveIndirect()->toString());
    }

    public function testBuildServerTableRequestMethod(): void
    {
        putenv('REQUEST_METHOD=GET');
        $server = SuperglobalRefreshJitHelper::buildServerTable();
        $method = $server->find('REQUEST_METHOD');
        $this->assertNotNull($method);
        $this->assertSame('GET', $method->resolveIndirect()->toString());
        $software = $server->find('SERVER_SOFTWARE');
        $this->assertNotNull($software);
        $this->assertSame('PHP-Compiler-AOT', $software->resolveIndirect()->toString());
    }

    public function testBuildCookieTable(): void
    {
        putenv('HTTP_COOKIE=session=abc123');
        $cookie = SuperglobalRefreshJitHelper::buildCookieTable();
        $session = $cookie->find('session');
        $this->assertNotNull($session);
        $this->assertSame('abc123', $session->resolveIndirect()->toString());
    }

    public function testBuildRequestMergesQueryAndPost(): void
    {
        putenv('QUERY_STRING=a=1');
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=b=2');
        putenv('CONTENT_TYPE=application/x-www-form-urlencoded');
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request = SuperglobalRefreshJitHelper::buildRequestTable();
        $this->assertSame('1', $this->readString($request, 'a'));
        $this->assertSame('2', $this->readString($request, 'b'));
    }

    private function readString(\PHPCompiler\VM\HashTable $ht, string $key): string
    {
        $var = $ht->find($key);
        $this->assertNotNull($var);
        $this->assertSame(Variable::TYPE_STRING, $var->resolveIndirect()->type);

        return $var->resolveIndirect()->toString();
    }
}
