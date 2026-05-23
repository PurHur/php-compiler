<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\SourceBundler;

/**
 * AOT bundle must not leave top-level return from config includes (issue #452, #485).
 */
final class SourceBundlerTest extends TestCase
{
    public function testReturnOnlyConfigIncludeBecomesAssignment(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/config.php',
                "<?php\ndeclare(strict_types=1);\n\nreturn ['app_name' => 'TestApp'];\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\n\$config = require __DIR__ . '/../config.php';\necho \$config['app_name'];\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/config.php') ?: $dir.'/config.php']
            );

            $this->assertStringNotContainsString('return [', $bundled);
            $this->assertStringContainsString("\$config = ['app_name' => 'TestApp'];", $bundled);
            $this->assertStringContainsString("echo \$config['app_name'];", $bundled);
        } finally {
            @unlink($dir.'/config.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testMethodLevelLiteralIncludeIsNotBundled(): void
    {
        $entry = realpath(dirname(__DIR__, 2).'/fixtures/aot/cases/method_include_void/entry.php');
        $this->assertNotFalse($entry);
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $includes = \PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
        $this->assertSame([], $includes, 'template includes inside methods must stay JIT-inlined, not prepended at file scope');

        [$bundled] = SourceBundler::bundleForAot($entry, $includes, null);
        $this->assertStringNotContainsString("echo \$title", $bundled);
        $this->assertStringContainsString("include __DIR__", $bundled);
    }

    public function testRewriteDirConstantSkipsStringLiterals(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/lib.php',
                "<?php\ndeclare(strict_types=1);\n\n"
                ."\$path = __DIR__ . '/x.php';\n"
                ."\$err = '__DIR__/__FILE__ used without script context';\n"
                ."\$err2 = \"__DIR__/__FILE__ used without script context\";\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\nrequire __DIR__ . '/../lib.php';\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/lib.php') ?: $dir.'/lib.php']
            );

            $this->assertStringContainsString(
                "'__DIR__/__FILE__ used without script context'",
                $bundled
            );
            $this->assertStringContainsString(
                '"__DIR__/__FILE__ used without script context"',
                $bundled
            );
            $this->assertStringNotContainsString('$path = __DIR__', $bundled);
            $this->assertStringContainsString("\$path = ", $bundled);
            $this->assertStringContainsString("'/x.php'", $bundled);
        } finally {
            @unlink($dir.'/lib.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testBundleEmitsSingleStrictTypesDeclare(): void
    {
        $dir = sys_get_temp_dir().'/phpc_bundle_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($dir));
        $this->assertTrue(mkdir($dir.'/public', 0777, true));
        try {
            file_put_contents(
                $dir.'/lib.php',
                "<?php\ndeclare(strict_types=1);\n\nfunction lib_fn(): int { return 1; }\n"
            );
            file_put_contents(
                $dir.'/public/index.php',
                "<?php\ndeclare(strict_types=1);\n\nrequire __DIR__ . '/../lib.php';\necho lib_fn();\n"
            );

            [$bundled] = SourceBundler::bundleForAot(
                $dir.'/public/index.php',
                [realpath($dir.'/lib.php') ?: $dir.'/lib.php']
            );

            $this->assertSame(1, substr_count($bundled, 'declare(strict_types=1);'));
        } finally {
            @unlink($dir.'/lib.php');
            @unlink($dir.'/public/index.php');
            @rmdir($dir.'/public');
            @rmdir($dir);
        }
    }

    public function testBundleMiniWebAppUsesLiteralDirForMethodIncludes(): void
    {
        $entry = realpath(dirname(__DIR__, 3).'/examples/003-MiniWebApp/public/index.php');
        $this->assertNotFalse($entry);
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $includes = \PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
        $root = DeployRoot::findProjectRootForPath($entry);
        $this->assertNotNull($root);
        [$bundled] = SourceBundler::bundleForAot($entry, $includes, $root);

        $this->assertStringNotContainsString('phpc_deploy_path(', $bundled);
        $this->assertStringContainsString('/templates/layout.php', $bundled);
        $this->assertStringContainsString(
            var_export(realpath($root.'/src') ?: $root.'/src', true),
            $bundled
        );
    }
}
