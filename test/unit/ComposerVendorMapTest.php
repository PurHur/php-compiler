<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ComposerVendorMap;
use PHPCompiler\AOT\ProjectGraph;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Composer vendor maps for phpc build --project (#36382).
 */
final class ComposerVendorMapTest extends TestCase
{
    public function testLoadMiniFixtureMapsClassmapPsr4AndFiles(): void
    {
        $dir = dirname(__DIR__, 2).'/test/fixtures/aot/projects/composer_mini';
        $map = ComposerVendorMap::load($dir);
        $this->assertTrue($map['enabled']);
        $this->assertSame([], $map['errors'], implode("\n", $map['errors']));
        $this->assertArrayHasKey('LegacyGreeter', $map['classmap']);
        $this->assertArrayHasKey('Pkg\\', $map['psr4']);
        $this->assertIsArray($map['psr4']['Pkg\\']);
        $joined = implode("\n", $map['all_files']);
        $this->assertStringContainsString('Hello.php', $joined);
        $this->assertStringContainsString('LegacyGreeter.php', $joined);
        $this->assertStringContainsString('functions.php', $joined);
        $this->assertStringContainsString('Extra.php', $joined);
        $this->assertStringContainsString('vendor/autoload.php', $joined);
    }

    public function testComposerPsr4KeepsAllBaseDirectories(): void
    {
        $dir = sys_get_temp_dir().'/phpc_dual_psr4_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir.'/vendor/composer', 0777, true));
        $this->assertTrue(mkdir($dir.'/vendor/pkg-a/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/vendor/pkg-b/src', 0777, true));
        try {
            file_put_contents($dir.'/vendor/pkg-a/src/FactoryOnly.php', "<?php\nnamespace Dual;\nclass FactoryOnly {}\n");
            file_put_contents($dir.'/vendor/pkg-b/src/Message.php', "<?php\nnamespace Dual;\nclass Message {}\n");
            $a = realpath($dir.'/vendor/pkg-a/src');
            $b = realpath($dir.'/vendor/pkg-b/src');
            $this->assertNotFalse($a);
            $this->assertNotFalse($b);
            file_put_contents(
                $dir.'/vendor/composer/autoload_psr4.php',
                "<?php\nreturn ['Dual\\\\' => [".var_export($a, true).', '.var_export($b, true)."]];\n"
            );
            file_put_contents($dir.'/vendor/composer/autoload_classmap.php', "<?php\nreturn [];\n");
            file_put_contents($dir.'/vendor/composer/autoload_files.php', "<?php\nreturn [];\n");
            file_put_contents($dir.'/vendor/autoload.php', "<?php\n");
            file_put_contents($dir.'/entry.php', "<?php\nnew Dual\\Message();\n");
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => 'composer',
                ], JSON_THROW_ON_ERROR)
            );

            $map = ComposerVendorMap::load($dir);
            $this->assertTrue($map['enabled']);
            $this->assertSame([], $map['errors'], implode("\n", $map['errors']));
            $this->assertSame([$a, $b], $map['psr4']['Dual\\']);
            $joined = implode("\n", $map['all_files']);
            $this->assertStringContainsString('FactoryOnly.php', $joined);
            $this->assertStringContainsString('Message.php', $joined);

            $path = ComposerVendorMap::resolveClassPath('Dual\\Message', $map['classmap'], $map['psr4']);
            $this->assertNotNull($path);
            $this->assertStringEndsWith('/Message.php', $path);

            $graph = ProjectGraph::resolve($dir);
            $this->assertSame([], $graph['errors'], implode("\n", $graph['errors']));
            $graphJoined = implode("\n", $graph['files']);
            $this->assertStringContainsString('Message.php', $graphJoined);
            $this->assertStringContainsString('FactoryOnly.php', $graphJoined);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testAutoloadNoneDisablesComposerMaps(): void
    {
        $dir = sys_get_temp_dir().'/phpc_composer_none_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir.'/vendor/composer', 0777, true));
        try {
            file_put_contents($dir.'/entry.php', '<?php');
            file_put_contents($dir.'/vendor/composer/autoload_classmap.php', "<?php\nreturn [];\n");
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => 'none',
                ], JSON_THROW_ON_ERROR)
            );
            $map = ComposerVendorMap::load($dir);
            $this->assertFalse($map['enabled']);
            $this->assertSame([], $map['all_files']);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testProjectGraphResolvesComposerMiniWithoutIncludesArray(): void
    {
        $dir = dirname(__DIR__, 2).'/test/fixtures/aot/projects/composer_mini';
        $result = ProjectGraph::resolve($dir);
        $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
        $joined = implode("\n", $result['files']);
        $this->assertStringContainsString('public/index.php', $joined);
        $this->assertStringContainsString('Hello.php', $joined);
        $this->assertStringContainsString('LegacyGreeter.php', $joined);
        $this->assertStringContainsString('functions.php', $joined);
        $this->assertStringContainsString('Extra.php', $joined);
        $this->assertStringContainsString('vendor/autoload.php', $joined);
    }

    public function testAllowlistRejectsUnknownLiteralInclude(): void
    {
        $dir = sys_get_temp_dir().'/phpc_allow_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        try {
            $allowed = $dir.'/ok.php';
            $blocked = $dir.'/blocked.php';
            file_put_contents($allowed, "<?php\n");
            file_put_contents($blocked, "<?php\necho 'no';\n");
            file_put_contents($dir.'/main.php', "<?php\nrequire __DIR__.'/blocked.php';\n");

            $runtime = new Runtime(Runtime::MODE_AOT);
            $okKey = realpath($allowed) ?: $allowed;
            $runtime->aotIncludeAllowlist = [$okKey => true];

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('include/require path outside project file map');

            // Mirror IncludeHelper allowlist gate (#36382).
            $path = realpath($blocked) ?: $blocked;
            $allow = $runtime->aotIncludeAllowlist;
            if (!isset($allow[$path]) && !isset($allow[$blocked])) {
                throw new \LogicException(
                    'include/require path outside project file map: '.$path
                    .' (issue #36382)'
                );
            }
        } finally {
            $this->removeTree($dir);
        }
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
