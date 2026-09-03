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
        $this->assertSame(ComposerVendorMap::CLOSURE_REACHABLE, $map['closure']);
        $this->assertSame([], $map['errors'], implode("\n", $map['errors']));
        $this->assertArrayHasKey('LegacyGreeter', $map['classmap']);
        $this->assertArrayHasKey('Pkg\\', $map['psr4']);
        $this->assertIsArray($map['psr4']['Pkg\\']);
        $joined = implode("\n", $map['all_files']);
        // Reachable seeds: classmap + files + include_roots + autoload — not whole PSR-4 trees.
        $this->assertStringContainsString('LegacyGreeter.php', $joined);
        $this->assertStringContainsString('functions.php', $joined);
        $this->assertStringContainsString('Extra.php', $joined);
        $this->assertStringContainsString('vendor/autoload.php', $joined);
        $this->assertStringNotContainsString('Hello.php', $joined);
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
            $this->assertSame(ComposerVendorMap::CLOSURE_REACHABLE, $map['closure']);
            $this->assertSame([], $map['errors'], implode("\n", $map['errors']));
            $this->assertSame([$a, $b], $map['psr4']['Dual\\']);
            $seedJoined = implode("\n", $map['all_files']);
            $this->assertStringNotContainsString('FactoryOnly.php', $seedJoined);
            $this->assertStringNotContainsString('Message.php', $seedJoined);

            $path = ComposerVendorMap::resolveClassPath('Dual\\Message', $map['classmap'], $map['psr4']);
            $this->assertNotNull($path);
            $this->assertStringEndsWith('/Message.php', $path);

            $graph = ProjectGraph::resolve($dir);
            $this->assertSame([], $graph['errors'], implode("\n", $graph['errors']));
            $graphJoined = implode("\n", $graph['files']);
            $this->assertStringContainsString('Message.php', $graphJoined);
            // Unreferenced sibling under the same prefix stays out of the reachable compile graph.
            $this->assertStringNotContainsString('FactoryOnly.php', $graphJoined);

            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => 'composer',
                    'composer_closure' => 'all',
                ], JSON_THROW_ON_ERROR)
            );
            $allMap = ComposerVendorMap::load($dir);
            $this->assertSame(ComposerVendorMap::CLOSURE_ALL, $allMap['closure']);
            $allJoined = implode("\n", $allMap['all_files']);
            $this->assertStringContainsString('FactoryOnly.php', $allJoined);
            $this->assertStringContainsString('Message.php', $allJoined);
        } finally {
            $this->removeTree($dir);
        }
    }

    /** Trait uses must expand before the using class (#36382 Nyholm MessageTrait). */
    public function testAutoloadDiscoveryIncludesTraitsBeforeUsingClass(): void
    {
        $dir = sys_get_temp_dir().'/phpc_trait_disc_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        try {
            file_put_contents(
                $dir.'/src/HelloTrait.php',
                "<?php\nnamespace Disc;\ntrait HelloTrait { public function hi(): string { return 'hi'; } }\n"
            );
            file_put_contents(
                $dir.'/src/Greeter.php',
                "<?php\nnamespace Disc;\nclass Greeter { use HelloTrait; }\n"
            );
            file_put_contents($dir.'/entry.php', "<?php\nnew Disc\\Greeter();\n");
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'entry.php',
                    'binary' => '.phpc/bin/app',
                    'autoload' => [
                        'psr-4' => ['Disc\\' => 'src/'],
                    ],
                ], JSON_THROW_ON_ERROR)
            );

            $graph = ProjectGraph::resolve($dir);
            $this->assertSame([], $graph['errors'], implode("\n", $graph['errors']));
            $rels = [];
            foreach ($graph['files'] as $abs) {
                $rels[] = basename($abs);
            }
            $this->assertContains('HelloTrait.php', $rels);
            $this->assertContains('Greeter.php', $rels);
            $this->assertLessThan(
                array_search('Greeter.php', $rels, true),
                array_search('HelloTrait.php', $rels, true),
                'trait file must precede class that use-s it: '.implode(',', $rels)
            );
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

            $path = realpath($blocked) ?: $blocked;
            $allow = $runtime->aotIncludeAllowlist;
            if (!\PHPCompiler\VM\ProjectIncludeAllowlist::isAllowed($path, $allow)) {
                throw new \LogicException(
                    \PHPCompiler\VM\ProjectIncludeAllowlist::denyMessage($path)
                );
            }
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testVmComputedIncludeOutsideAllowlistThrowsError(): void
    {
        $dir = sys_get_temp_dir().'/phpc_vm_allow_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        try {
            $ok = $dir.'/ok.php';
            $blocked = $dir.'/blocked.php';
            file_put_contents($ok, "<?php\nreturn 1;\n");
            file_put_contents($blocked, "<?php\nreturn 2;\n");
            $main = $dir.'/main.php';
            file_put_contents(
                $main,
                "<?php\n\$p = __DIR__.'/blocked.php';\nrequire \$p;\n"
            );

            $runtime = new Runtime();
            $okKey = realpath($ok) ?: $ok;
            $runtime->aotIncludeAllowlist = [$okKey => true];
            $block = $runtime->parseAndCompileFile($main);
            $this->assertNotNull($block);

            $this->expectException(\Error::class);
            $this->expectExceptionMessage('include/require path outside project file map');
            $runtime->run($block);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testVmComputedIncludeInsideAllowlistRuns(): void
    {
        $dir = sys_get_temp_dir().'/phpc_vm_allow_ok_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        try {
            $ok = $dir.'/ok.php';
            file_put_contents($ok, "<?php\necho 'in-map';\n");
            $main = $dir.'/main.php';
            file_put_contents(
                $main,
                "<?php\n\$p = __DIR__.'/ok.php';\nrequire \$p;\n"
            );

            $runtime = new Runtime();
            $okKey = realpath($ok) ?: $ok;
            $mainKey = realpath($main) ?: $main;
            $runtime->aotIncludeAllowlist = [$okKey => true, $mainKey => true];
            $block = $runtime->parseAndCompileFile($main);
            $this->assertNotNull($block);

            ob_start();
            $runtime->run($block);
            $out = (string) ob_get_clean();
            $this->assertSame('in-map', $out);
        } finally {
            $this->removeTree($dir);
        }
    }

    public function testProjectIncludeAllowlistEmitKeysStable(): void
    {
        $keys = \PHPCompiler\VM\ProjectIncludeAllowlist::emitKeys([
            '/b' => true,
            '/a' => true,
            '/b' => true,
        ]);
        $this->assertSame(['/a', '/b'], $keys);
    }

    /**
     * Bare AOT must not stub vendor/autoload.php without a project file map — that
     * silently dropped classes for `$path = __DIR__.'/vendor/autoload.php'; require $path`
     * (#36382 differential composer_computed_include).
     */
    public function testIncludeHelperStubsComposerAutoloadOnlyWithProjectAllowlist(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/IncludeHelper.php');
        $this->assertStringContainsString('isComposerAutoloadPhp($path)', $source);
        $this->assertStringContainsString(
            'Bare AOT (no file map): follow the file',
            $source
        );
        $pos = strpos($source, 'isComposerAutoloadPhp($path)');
        $this->assertNotFalse($pos);
        $window = substr($source, $pos, 900);
        $this->assertStringContainsString('is_array($allow) && [] !== $allow', $window);
        $this->assertStringContainsString('assignIncludeResult', $window);
        $this->assertStringContainsString('compileIncludedFile', $window);
    }

    /**
     * phpc build --project: out-of-map require must fail loudly with the path (#36382).
     * Folded `__DIR__.'/…'` literals are rejected at compile time; non-foldable
     * computed paths Error at runtime — both must name the path (never silent).
     *
     * @group llvm
     */
    public function testAotProjectComputedIncludeOutsideMapRaisesWithPath(): void
    {
        $dir = sys_get_temp_dir().'/phpc_aot_omap_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        try {
            $ok = $dir.'/ok.php';
            $blocked = $dir.'/blocked.php';
            $entry = $dir.'/main.php';
            file_put_contents($ok, "<?php\n");
            file_put_contents($blocked, "<?php\necho 'leak';\n");
            // Non-foldable path: basename from argv so ConstStringFolder cannot literalize.
            file_put_contents(
                $entry,
                "<?php\n\$n = \$argv[1] ?? 'blocked';\n\$p = __DIR__.'/'.\$n.'.php';\nrequire \$p;\necho 'ok';\n"
            );
            file_put_contents(
                $dir.'/phpc.json',
                json_encode([
                    'entry' => 'main.php',
                    'binary' => '.phpc/bin/omap',
                    'includes' => ['ok.php'],
                    'autoload' => 'none',
                ], JSON_THROW_ON_ERROR)
            );

            $root = dirname(__DIR__, 2);
            $bin = $dir.'/.phpc/bin/omap';
            @mkdir($dir.'/.phpc/bin', 0777, true);
            $cmd = 'cd '.escapeshellarg($root)
                .' && php bin/phpc.php build --project '.escapeshellarg($dir).' 2>&1';
            exec($cmd, $clog, $crc);
            $this->assertSame(0, $crc, implode("\n", $clog));
            $this->assertFileExists($bin);

            $out = [];
            exec(escapeshellarg($bin).' blocked 2>&1', $out, $rc);
            $joined = implode("\n", $out);
            $this->assertNotSame(0, $rc, $joined);
            $this->assertStringContainsString('include/require path outside project file map', $joined);
            $this->assertStringContainsString('blocked.php', $joined);
            $this->assertStringNotContainsString('leak', $joined);
        } finally {
            $this->removeTree($dir);
        }
    }

    /**
     * scripts under the compiler checkout must not invent the repo vendor/ map
     * (#36382 — benchmarks/simple.php mega-bundle / yay parse error).
     */
    public function testExpandIncludesDoesNotInventRepoVendorForBareScripts(): void
    {
        $repo = dirname(__DIR__, 2);
        $simple = $repo.'/benchmarks/simple.php';
        $this->assertFileExists($simple);
        $out = ComposerVendorMap::expandIncludesForAutoload($simple, []);
        $this->assertSame([], $out);

        $fibo = $repo.'/benchmarks/fibo(30).php';
        $this->assertFileExists($fibo);
        $this->assertSame([], ComposerVendorMap::expandIncludesForAutoload($fibo, []));
    }

    /**
     * Nearby vendor/autoload guesses still work when the entry requires Composer.
     */
    public function testExpandIncludesGuessesParentVendorWhenEntryRequiresAutoload(): void
    {
        $dir = sys_get_temp_dir().'/phpc_expand_guess_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir.'/src', 0777, true));
        $this->assertTrue(mkdir($dir.'/vendor/composer', 0777, true));
        try {
            file_put_contents($dir.'/vendor/autoload.php', "<?php\nrequire __DIR__.'/composer/autoload_real.php';\n");
            file_put_contents($dir.'/vendor/composer/autoload_real.php', "<?php\n");
            file_put_contents($dir.'/vendor/composer/autoload_classmap.php', "<?php\nreturn [];\n");
            file_put_contents($dir.'/vendor/composer/autoload_psr4.php', "<?php\nreturn [];\n");
            file_put_contents($dir.'/vendor/composer/autoload_files.php', "<?php\nreturn [];\n");
            $entry = $dir.'/src/index.php';
            file_put_contents(
                $entry,
                "<?php\nrequire __DIR__.'/../vendor/autoload.php';\necho 'ok';\n"
            );

            $out = ComposerVendorMap::expandIncludesForAutoload($entry, []);
            $joined = implode("\n", $out);
            $this->assertStringContainsString('vendor/autoload.php', $joined);
            $this->assertStringContainsString('autoload_real.php', $joined);
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
