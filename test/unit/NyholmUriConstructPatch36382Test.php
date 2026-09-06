<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Nyholm Uri::__construct fast-path absolute paths under AOT.
 *
 * @group aot
 */
final class NyholmUriConstructPatch36382Test extends TestCase
{
    public function testPatchRewritesCtorToAbsolutePathFastPath(): void
    {
        $repo = dirname(__DIR__, 2);
        $patch = $repo.'/script/composer/patch-nyholm-uri-construct-36382.php';
        $dir = sys_get_temp_dir().'/phpc_uri_36382_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $tmp = $dir.'/Uri.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Nyholm\Psr7;
class Uri {
    private $scheme = '';
    private $userInfo = '';
    private $host = '';
    private $port;
    private $path = '';
    private $query = '';
    private $fragment = '';
    public function __construct(string $uri = '')
    {
        if ('' !== $uri) {
            if (false === $parts = \parse_url($uri)) {
                throw new \InvalidArgumentException(\sprintf('Unable to parse URI: "%s"', $uri));
            }

            // Apply parse_url parts to a URI.
            $this->scheme = isset($parts['scheme']) ? \strtr($parts['scheme'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->userInfo = $parts['user'] ?? '';
            $this->host = isset($parts['host']) ? \strtr($parts['host'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') : '';
            $this->port = isset($parts['port']) ? $this->filterPort($parts['port']) : null;
            $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
            $this->query = isset($parts['query']) ? $this->filterQueryAndFragment($parts['query']) : '';
            $this->fragment = isset($parts['fragment']) ? $this->filterQueryAndFragment($parts['fragment']) : '';
            if (isset($parts['pass'])) {
                $this->userInfo .= ':' . $parts['pass'];
            }
        }
    }
}
PHP);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $text = (string) file_get_contents($tmp);
        $this->assertStringContainsString('AOT (#36382): fast-path absolute path', $text);
        $this->assertStringContainsString("false === \\strpos(\$uri, '://')", $text);
        $this->assertStringNotContainsString("\$parts['user'] ??", $text);
        exec('php '.escapeshellarg($patch).' '.escapeshellarg($tmp).' 2>&1', $out2, $ec2);
        $this->assertSame(0, $ec2);
        $this->assertStringContainsString('already patched', implode("\n", $out2));
        @unlink($tmp);
        @rmdir($dir);
    }
}
