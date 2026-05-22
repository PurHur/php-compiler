<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPUnit\Framework\TestCase;

/**
 * Static file resolution for phpc serve (issue #594).
 */
final class DevServerStaticTest extends TestCase
{
    public function testResolveStaticFilePrefersDocrootOverAssets(): void
    {
        $dir = sys_get_temp_dir().'/phpc_serve_static_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        $this->assertTrue(mkdir($dir.'/public/assets', 0777, true));
        $this->assertTrue(mkdir($dir.'/assets', 0777, true));
        try {
            file_put_contents($dir.'/public/assets/style.css', 'from-public');
            file_put_contents($dir.'/assets/style.css', 'from-assets');
            $public = realpath($dir.'/public');
            $assets = realpath($dir.'/assets');
            $this->assertNotFalse($public);
            $this->assertNotFalse($assets);

            $resolved = DevServer::resolveStaticFile($public, '/assets/style.css', $assets);
            $this->assertSame($public.'/assets/style.css', $resolved);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testResolveAssetsFileMapsAssetsUrlPrefix(): void
    {
        $dir = sys_get_temp_dir().'/phpc_serve_static_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        try {
            file_put_contents($dir.'/style.css', 'body {}');
            $assets = realpath($dir);
            $this->assertNotFalse($assets);

            $this->assertSame(
                $assets.'/style.css',
                DevServer::resolveAssetsFile($assets, '/assets/style.css')
            );
            $this->assertNull(DevServer::resolveAssetsFile($assets, '/other/style.css'));
            $this->assertNull(DevServer::resolveAssetsFile($assets, '/assets/../style.css'));
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testIsSafeUrlPathRejectsTraversal(): void
    {
        $this->assertFalse(DevServer::isSafeUrlPath('/assets/../style.css'));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
