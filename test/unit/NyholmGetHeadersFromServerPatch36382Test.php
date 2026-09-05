<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** #36382 — Nyholm getHeadersFromServer return-type strip for AOT. */
final class NyholmGetHeadersFromServerPatch36382Test extends TestCase
{
    public function testPatchRemovesTypedArrayReturn(): void
    {
        $tmp = sys_get_temp_dir().'/ServerRequestCreator_36382_'.getmypid().'.php';
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
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/composer/patch-nyholm-get-headers-from-server-36382.php';
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($tmp).' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $patched = (string) file_get_contents($tmp);
        $this->assertStringContainsString('AOT (#36382): typed array return', $patched);
        $this->assertStringContainsString('public static function getHeadersFromServer(array $server)', $patched);
        $this->assertStringNotContainsString('getHeadersFromServer(array $server): array', $patched);
        @unlink($tmp);
    }
}
