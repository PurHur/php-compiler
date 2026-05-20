<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\DevServer;
use PHPUnit\Framework\TestCase;

final class DevServerHeadersTest extends TestCase
{
    public function testHeaderNameToServerKey(): void
    {
        $this->assertSame('HTTP_HOST', DevServer::headerNameToServerKey('host'));
        $this->assertSame('HTTP_X_CUSTOM', DevServer::headerNameToServerKey('x-custom'));
        $this->assertSame('HTTP_USER_AGENT', DevServer::headerNameToServerKey('User-Agent'));
    }

    public function testHttpHeadersToServerVars(): void
    {
        $vars = DevServer::httpHeadersToServerVars([
            'host' => 'example.test',
            'x-custom' => '1',
        ]);
        $this->assertSame([
            'HTTP_HOST' => 'example.test',
            'HTTP_X_CUSTOM' => '1',
        ], $vars);
    }

    public function testRejectsHeaderValueWithNewlines(): void
    {
        $vars = DevServer::httpHeadersToServerVars([
            'x-inject' => "evil\r\nSet-Cookie: bad=1",
        ]);
        $this->assertSame([], $vars);
    }

    public function testResolveDirectoryIndexPrefersIndexPhp(): void
    {
        $dir = sys_get_temp_dir().'/phpc_devserver_idx_'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/index.php', '<?php');
        file_put_contents($dir.'/example.php', '<?php');

        $this->assertSame('/index.php', DevServer::resolveDirectoryIndex($dir));

        @unlink($dir.'/index.php');
        $this->assertSame('/example.php', DevServer::resolveDirectoryIndex($dir));

        @unlink($dir.'/example.php');
        $this->assertSame('/index.php', DevServer::resolveDirectoryIndex($dir));

        @rmdir($dir);
    }

    public function testResolveDirectoryIndexFromManifest(): void
    {
        $dir = sys_get_temp_dir().'/phpc_devserver_mf_'.bin2hex(random_bytes(4));
        mkdir($dir.'/public', 0777, true);
        file_put_contents($dir.'/public/index.php', '<?php');

        $this->assertSame(
            '/public/index.php',
            DevServer::resolveDirectoryIndex($dir, ['index' => 'public/index.php'])
        );

        @unlink($dir.'/public/index.php');
        @rmdir($dir.'/public');
        @rmdir($dir);
    }

    public function testContentLengthForRequestUsesBodySize(): void
    {
        $body = 'abcdefghijkl';
        $this->assertSame('12', DevServer::contentLengthForRequest(['content-length' => '99'], $body));
        $this->assertNull(DevServer::contentLengthForRequest([], $body));
    }

    public function testParsePeerAddressIpv4(): void
    {
        $parsed = DevServer::parsePeerAddress('127.0.0.1:54321');
        $this->assertNotNull($parsed);
        $this->assertSame(['127.0.0.1', '54321'], $parsed);
    }

    public function testParsePeerAddressIpv6(): void
    {
        $parsed = DevServer::parsePeerAddress('[::1]:54321');
        $this->assertNotNull($parsed);
        $this->assertSame(['::1', '54321'], $parsed);
    }

    public function testParsePeerAddressRejectsInvalid(): void
    {
        $this->assertNull(DevServer::parsePeerAddress(''));
        $this->assertNull(DevServer::parsePeerAddress('no-port'));
        $this->assertNull(DevServer::parsePeerAddress('[::1]'));
    }
}
