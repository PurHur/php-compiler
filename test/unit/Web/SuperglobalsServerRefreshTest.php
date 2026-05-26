<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * $_SERVER keys refresh on repeated populateFromEnvironment (issue #2257).
 */
final class SuperglobalsServerRefreshTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        foreach ([
            'REQUEST_METHOD',
            'PATH_INFO',
            'SCRIPT_NAME',
            'HTTPS',
            'HTTP_X_FORWARDED_PROTO',
        ] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    public function testPathInfoUpdatesOnSecondPopulate(): void
    {
        putenv('PATH_INFO=/home');
        putenv('SCRIPT_NAME=/index.php');
        putenv('REQUEST_METHOD=GET');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('/home', $this->readServer($server, 'PATH_INFO'));

        putenv('PATH_INFO=/hello');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, 'name=Dev', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('/hello', $this->readServer($server, 'PATH_INFO'));
        $this->assertSame('GET', $this->readServer($server, 'REQUEST_METHOD'));
    }

    public function testRequestMethodUpdatesOnSecondPopulate(): void
    {
        putenv('REQUEST_METHOD=GET');
        putenv('SCRIPT_NAME=/index.php');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('GET', $this->readServer($server, 'REQUEST_METHOD'));

        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=name=Ada');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', 'name=Ada');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('POST', $this->readServer($server, 'REQUEST_METHOD'));
    }

    public function testHttpsClearsWhenForwardedProtoDowngrades(): void
    {
        putenv('HTTP_X_FORWARDED_PROTO=https');
        putenv('SCRIPT_NAME=/index.php');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('https', $this->readServer($server, 'REQUEST_SCHEME'));
        $this->assertSame('on', $this->readServer($server, 'HTTPS'));

        putenv('HTTP_X_FORWARDED_PROTO=http');
        Superglobals::populateFromEnvironment($this->runtime->vmContext, '', '');

        $server = $this->runtime->vmContext->getSuperglobal('_SERVER')->toArray();
        $this->assertSame('http', $this->readServer($server, 'REQUEST_SCHEME'));
        $this->assertSame('', $this->readServer($server, 'HTTPS'));
    }

    private function readServer(\PHPCompiler\VM\HashTable $server, string $key): string
    {
        $var = $server->find($key);
        if (null === $var) {
            return '';
        }
        $resolved = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_STRING !== $resolved->type) {
            return '';
        }

        return $resolved->toString();
    }
}
