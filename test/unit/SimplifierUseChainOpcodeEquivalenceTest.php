<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Default PHPCFG Simplifier use-chain path must lower to the same opcodes as legacy (#23056, #36225).
 *
 * CFG pretty-print / inferred type labels can still differ (replacement ORDER); the compile
 * spine cares about opcode identity. Opt out remains PHPCFG_SIMPLIFIER_USECHAIN=0 /
 * PHPCFG_SIMPLIFIER_LEGACY=1.
 */
final class SimplifierUseChainOpcodeEquivalenceTest extends TestCase
{
    private const MIN_CORPUS = 100;

    protected function tearDown(): void
    {
        putenv('PHPCFG_SIMPLIFIER_USECHAIN');
        putenv('PHPCFG_SIMPLIFIER_LEGACY');
    }

    public function testCorpusCoversAtLeastMinimumFileCount(): void
    {
        $root = \dirname(__DIR__, 2);
        $this->assertGreaterThanOrEqual(
            self::MIN_CORPUS,
            \count(self::collectCorpusRels($root)),
            'Simplifier equivalence corpus must cover at least '.self::MIN_CORPUS.' files (#36225)'
        );
    }

    /**
     * @return list<string> repo-relative paths (deterministic sample, ≥ MIN_CORPUS)
     */
    private static function collectCorpusRels(string $root): array
    {
        $scanRoots = [
            'lib',
            'ext/standard',
            'examples',
            'test/selfhost/compiler_lib_spine_smoke',
        ];
        $byRoot = [];
        foreach ($scanRoots as $scanRoot) {
            $absRoot = $root.'/'.$scanRoot;
            if (!is_dir($absRoot)) {
                continue;
            }
            $rels = [];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if ($file->getSize() > 512 * 1024) {
                    continue;
                }
                $rel = substr($path, \strlen($root) + 1);
                if ('test/selfhost/compiler_lib_spine_smoke/main.php' === $rel) {
                    continue;
                }
                $rels[] = $rel;
            }
            sort($rels, SORT_STRING);
            $byRoot[$scanRoot] = $rels;
        }

        $picked = [];
        $seen = [];
        $perRoot = max(1, intdiv(self::MIN_CORPUS, max(1, \count($byRoot))));
        foreach ($byRoot as $rels) {
            foreach (\array_slice($rels, 0, $perRoot) as $rel) {
                if (!isset($seen[$rel])) {
                    $seen[$rel] = true;
                    $picked[] = $rel;
                }
            }
        }
        $all = [];
        foreach ($byRoot as $rels) {
            foreach ($rels as $rel) {
                if (!isset($seen[$rel])) {
                    $all[] = $rel;
                }
            }
        }
        sort($all, SORT_STRING);
        foreach ($all as $rel) {
            if (\count($picked) >= self::MIN_CORPUS) {
                break;
            }
            if (!isset($seen[$rel])) {
                $seen[$rel] = true;
                $picked[] = $rel;
            }
        }
        sort($picked, SORT_STRING);

        return $picked;
    }

    private function opcodesInSubprocess(string $file, string $mode): string
    {
        $root = \dirname(__DIR__, 2);
        $helper = $root.'/test/support/simplifier_opcode_dump.php';
        $cmd = [
            \PHP_BINARY,
            $helper,
            $file,
            $mode,
        ];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        if (!\is_resource($proc)) {
            $this->fail('proc_open failed for '.$file.' ('.$mode.')');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, trim($stderr) !== '' ? trim($stderr) : 'opcode dump failed');

        return (string) $stdout;
    }

    public function testDefaultUseChainMatchesLegacyOpcodesAcrossCorpus(): void
    {
        $root = \dirname(__DIR__, 2);
        $rels = self::collectCorpusRels($root);
        $this->assertGreaterThanOrEqual(self::MIN_CORPUS, \count($rels));
        foreach ($rels as $rel) {
            $file = $root.'/'.$rel;
            $legacy = $this->opcodesInSubprocess($file, 'legacy');
            $usechain = $this->opcodesInSubprocess($file, 'usechain');
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
        $legacy = $this->opcodesInSubprocess($file, 'legacy');
        $usechainExplicit = $this->opcodesInSubprocess($file, 'usechain');
        $this->assertSame($legacy, $usechainExplicit);
    }
}
