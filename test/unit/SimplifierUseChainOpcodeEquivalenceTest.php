<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Printer as OpCodePrinter;
use PHPUnit\Framework\TestCase;

/**
 * Default PHPCFG Simplifier use-chain path must lower to the same opcodes as legacy (#23056).
 *
 * CFG pretty-print / inferred type labels can still differ (replacement ORDER); the compile
 * spine cares about opcode identity. Opt out remains PHPCFG_SIMPLIFIER_USECHAIN=0 /
 * PHPCFG_SIMPLIFIER_LEGACY=1.
 */
final class SimplifierUseChainOpcodeEquivalenceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPCFG_SIMPLIFIER_USECHAIN');
        putenv('PHPCFG_SIMPLIFIER_LEGACY');
    }

    /** @return list<string> */
    private static function discoverCorpusPaths(): array
    {
        $root = \dirname(__DIR__, 2);
        $dirs = [
            $root.'/lib',
            $root.'/ext/standard',
            $root.'/examples',
            $root.'/bin',
        ];
        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, '/vendor/') || str_contains($path, '/build/')) {
                    continue;
                }
                $files[] = substr($path, \strlen($root) + 1);
            }
        }
        $spine = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
        if (is_readable($spine)) {
            $files[] = 'test/selfhost/compiler_lib_spine_smoke/main.php';
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private static function representativeSample(): array
    {
        $want = [
            'examples/000-HelloWorld/example.php',
            'lib/Printer.php',
            'lib/Block.php',
            'lib/Compiler.php',
            'lib/VM.php',
            'lib/JIT/Context.php',
            'ext/standard/Module.php',
            'ext/standard/PackJitHelper.php',
            'bin/print.php',
            'bin/vm.php',
            'test/selfhost/compiler_lib_spine_smoke/main.php',
        ];
        $root = \dirname(__DIR__, 2);
        $out = [];
        foreach ($want as $rel) {
            if (is_readable($root.'/'.$rel)) {
                $out[] = $rel;
            }
        }

        return $out;
    }

    private function opcodes(string $file, bool $legacy): string
    {
        if ($legacy) {
            putenv('PHPCFG_SIMPLIFIER_USECHAIN=0');
            putenv('PHPCFG_SIMPLIFIER_LEGACY=1');
        } else {
            putenv('PHPCFG_SIMPLIFIER_USECHAIN=1');
            putenv('PHPCFG_SIMPLIFIER_LEGACY');
        }
        $runtime = new Runtime();
        $code = (string) file_get_contents($file);
        $block = $runtime->compile($runtime->parse($code, $file));

        return (new OpCodePrinter())->print($block);
    }

    public function testCorpusHasMinimumFileCount(): void
    {
        $this->assertGreaterThanOrEqual(100, \count(self::discoverCorpusPaths()));
    }

    public function testDefaultUseChainMatchesLegacyOpcodesOnRepresentativeSample(): void
    {
        $root = \dirname(__DIR__, 2);
        foreach (self::representativeSample() as $rel) {
            $abs = $root.'/'.$rel;
            $legacy = $this->opcodes($abs, true);
            $usechain = $this->opcodes($abs, false);
            $this->assertSame(
                $legacy,
                $usechain,
                $rel.': use-chain opcodes diverged from legacy CFG walk'
            );
        }
    }

    /**
     * @group slow
     */
    public function testDefaultUseChainMatchesLegacyOpcodesOnFullCorpus(): void
    {
        $root = \dirname(__DIR__, 2);
        foreach (self::discoverCorpusPaths() as $rel) {
            $abs = $root.'/'.$rel;
            if (! is_readable($abs)) {
                continue;
            }
            $legacy = $this->opcodes($abs, true);
            $usechain = $this->opcodes($abs, false);
            $this->assertSame(
                $legacy,
                $usechain,
                $rel.': use-chain opcodes diverged from legacy CFG walk'
            );
        }
    }

    public function testLegacyEnvForcesCfgWalkPath(): void
    {
        $root = \dirname(__DIR__, 2);
        $file = $root.'/examples/000-HelloWorld/example.php';
        putenv('PHPCFG_SIMPLIFIER_USECHAIN=0');
        $a = $this->opcodes($file, true);
        putenv('PHPCFG_SIMPLIFIER_USECHAIN');
        putenv('PHPCFG_SIMPLIFIER_LEGACY=1');
        $runtime = new Runtime();
        $b = (new OpCodePrinter())->print(
            $runtime->compile($runtime->parse((string) file_get_contents($file), $file))
        );
        $this->assertSame($a, $b);
    }
}
