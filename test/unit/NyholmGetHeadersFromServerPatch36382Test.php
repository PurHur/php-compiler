<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #36382 — Nyholm getHeadersFromServer return-type strip for AOT. */
final class NyholmGetHeadersFromServerPatch36382Test extends TestCase
{
    public function testPatchRemovesTypedArrayReturn(): void
    {
        $dir = sys_get_temp_dir().'/nyholm_hdr_36382_'.getmypid();
        mkdir($dir);
        $tmp = $dir.'/ServerRequestCreator.php';
        $iface = $dir.'/ServerRequestCreatorInterface.php';
        file_put_contents($tmp, <<<'PHP'
<?php
namespace Nyholm\Psr7Server;
final class ServerRequestCreator
{
    public static function getHeadersFromServer(array $server): array
    {
        return [];
    }
}
PHP);
        file_put_contents($iface, <<<'PHP'
<?php
namespace Nyholm\Psr7Server;
interface ServerRequestCreatorInterface
{
    public static function getHeadersFromServer(array $server): array;
}
PHP);
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/composer/patch-nyholm-get-headers-from-server-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $patched = (string) file_get_contents($tmp);
        $this->assertStringContainsString('AOT (#36382): typed array return', $patched);
        $this->assertStringContainsString('public static function getHeadersFromServer(array $server)', $patched);
        $this->assertStringNotContainsString('getHeadersFromServer(array $server): array', $patched);
        $ifacePatched = (string) file_get_contents($iface);
        $this->assertStringContainsString('getHeadersFromServer(array $server);', $ifacePatched);
        $this->assertStringNotContainsString('getHeadersFromServer(array $server): array;', $ifacePatched);
        @unlink($tmp);
        @unlink($iface);
        @rmdir($dir);
    }
}

