<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\DeployRoot;

/**
 * CgiAotDriver binary resolution (issue #665).
 */
final class CgiAotDriverTest extends TestCase
{
    public function testResolveExplicitBinary(): void
    {
        $repo = dirname(__DIR__, 3);
        $compile = $repo.'/bin/compile.php';
        $this->assertFileExists($compile);

        $resolved = CgiAotDriver::resolveBinary($compile, null);
        $this->assertSame(realpath($compile), $resolved);
    }

    public function testResolveFromDeployRootBinApp(): void
    {
        $dist = sys_get_temp_dir().'/phpc_cgi_resolve_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dist.'/bin', 0777, true));
        $binary = $dist.'/bin/app';
        file_put_contents($binary, "#!/bin/sh\necho ok\n");
        chmod($binary, 0755);

        try {
            $resolved = CgiAotDriver::resolveBinary(null, $dist);
            $this->assertSame(realpath($binary), $resolved);
        } finally {
            @unlink($binary);
            @rmdir($dist.'/bin');
            @rmdir($dist);
        }
    }

    public function testResolveFailsWithoutBinaryOrDeployRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CgiAotDriver::resolveBinary(null, null);
    }

    public function testWrapperNameConstant(): void
    {
        $this->assertSame('cgi-wrapper', CgiAotDriver::WRAPPER_NAME);
        $this->assertSame('PHPC_DEPLOY_ROOT', DeployRoot::ENV);
    }
}
