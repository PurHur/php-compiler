<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** AOT include discovery must desugar pipe before PHPCfg parse (#4456). */
final class LiteralIncludeDiscoveryPipeTest extends TestCase
{
    public function testDiscoverDirectDoesNotFailOnPipeOperator(): void
    {
        $dir = sys_get_temp_dir().'/phpc_pipe_include_'.getmypid();
        $this->assertTrue(is_dir($dir) || mkdir($dir, 0775, true));
        $entry = $dir.'/entry.php';
        file_put_contents($entry, <<<'PHP'
<?php
echo "hi" |> strtoupper(...);
PHP
        );
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $paths = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
            $this->assertIsArray($paths);
        } finally {
            @unlink($entry);
            @rmdir($dir);
        }
    }

    public function testDiscoverBundleSkipsMethodBodyIncludes(): void
    {
        $dir = dirname(__DIR__, 3).'/examples/003-MiniWebApp';
        if (!is_file($dir.'/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing');
        }
        $runtime = new Runtime(Runtime::MODE_AOT);
        $entry = realpath($dir.'/public/index.php');
        $this->assertNotFalse($entry);
        $bundle = LiteralIncludeDiscovery::discoverBundleAbsolutePaths($runtime, $entry);
        $joined = implode("\n", $bundle);
        $this->assertStringContainsString('config.php', $joined);
        $this->assertStringContainsString('Router.php', $joined);
        $this->assertStringNotContainsString('templates/layout.php', $joined);
        $reachable = LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $entry);
        $this->assertStringContainsString('templates/layout.php', implode("\n", $reachable));
    }

    public function testDiscoverDirectResetsNameResolverBetweenFiles(): void
    {
        $dir = sys_get_temp_dir().'/phpc_use_reset_'.getmypid();
        $this->assertTrue(is_dir($dir) || mkdir($dir, 0775, true));
        $a = $dir.'/a.php';
        $b = $dir.'/b.php';
        $entry = $dir.'/entry.php';
        file_put_contents($a, <<<'PHP'
<?php
namespace Foo;
use PHPCompiler\JIT\Context;
class A {}
PHP
        );
        file_put_contents($b, <<<'PHP'
<?php
namespace Foo;
use PHPCompiler\JIT\Context;
class B {}
PHP
        );
        file_put_contents($entry, <<<'PHP'
<?php
require __DIR__.'/a.php';
require __DIR__.'/b.php';
PHP
        );
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $paths = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
            $this->assertCount(2, $paths);
            $transitive = LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $entry);
            $this->assertCount(2, $transitive);
        } finally {
            foreach ([$entry, $a, $b] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
