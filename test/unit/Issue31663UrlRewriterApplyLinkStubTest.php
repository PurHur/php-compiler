<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT must define `__phpc_url_rewriter_apply` so ob_* flush links (#31663).
 */
final class Issue31663UrlRewriterApplyLinkStubTest extends TestCase
{
    public function testHelloWorldAotLinksWithUrlRewriterApplyStub(): void
    {
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31663_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_31663_'.getmypid().'.bin';
        file_put_contents($src, "<?php echo \"hi\\n\";\n");
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, 'run: '.implode("\n", $runOut));
            $this->assertSame("hi\n", implode("\n", $runOut).([] === $runOut ? '' : "\n"));
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testEnsureLinkedInstallsIdentityStubNotDeclareOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UrlRewriterApplyRuntime.php');
        $this->assertStringContainsString('ensureIdentityStub', $source);
        $this->assertStringContainsString('ura_identity_stub', $source);
        $this->assertStringContainsString('openBridgeEntryBlock', $source);
        $this->assertMatchesRegularExpression(
            '/function ensureLinked\(Context \$context\): void\s*\{\s*self::ensureIdentityStub/s',
            $source
        );
    }
}
