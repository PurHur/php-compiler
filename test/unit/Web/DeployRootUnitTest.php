<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\DeployRoot;

final class DeployRootUnitTest extends TestCase
{
    public function testFindProjectRootForPath(): void
    {
        $dir = sys_get_temp_dir().'/phpc_deploy_find_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents($dir.'/phpc.json', '{}');
            $root = DeployRoot::findProjectRootForPath($dir.'/public/index.php');
            $this->assertSame(realpath($dir) ?: $dir, $root);
        } finally {
            @unlink($dir.'/phpc.json');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testRelativeDirFromProject(): void
    {
        $repo = realpath(dirname(__DIR__, 3).'/examples/003-MiniWebApp');
        if (false === $repo) {
            $this->markTestSkipped('003-MiniWebApp missing');
        }
        $this->assertSame('src', DeployRoot::relativeDirFromProject($repo.'/src', $repo));
        $this->assertSame('templates', DeployRoot::relativeDirFromProject($repo.'/templates', $repo));
        $this->assertSame('', DeployRoot::relativeDirFromProject($repo, $repo));
    }
}
