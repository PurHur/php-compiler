<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Differential fuzz generator / signature helpers (#36398).
 */
final class DifferentialFuzz36398Test extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root.'/script/fuzz/lib.php';
        require_once self::$root.'/script/fuzz/generate.php';
    }

    public function testSameSeedIsByteIdentical(): void
    {
        $a = fuzz_generate_program(42, 'auto');
        $b = fuzz_generate_program(42, 'auto');
        $this->assertSame($a, $b);
        $this->assertStringContainsString('@fuzz-seed: 42', $a);
    }

    public function testExplicitShapesAreDeterministic(): void
    {
        foreach (fuzz_known_shapes() as $shape) {
            $a = fuzz_generate_program(99, $shape);
            $b = fuzz_generate_program(99, $shape);
            $this->assertSame($a, $b, $shape);
            $this->assertStringContainsString('@fuzz-shape: '.$shape, $a);
        }
    }

    public function testGeneratedProgramsPassPhpLint(): void
    {
        $tmp = self::$root.'/build/fuzz-lint-phpunit';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0777, true);
        }
        for ($seed = 1; $seed <= 40; ++$seed) {
            $path = $tmp.'/s'.$seed.'.php';
            file_put_contents($path, fuzz_generate_program($seed, 'auto'));
            $cmd = 'php -l '.escapeshellarg($path).' 2>&1';
            exec($cmd, $lines, $rc);
            $this->assertSame(0, $rc, implode("\n", $lines));
        }
    }

    public function testSignatureCollapsesPaths(): void
    {
        $sig1 = fuzz_normalize_signature('vm_diff', 0, 0, "x\n", "/tmp/foo/bar.php:1\n");
        $sig2 = fuzz_normalize_signature('vm_diff', 0, 0, "x\n", "/compiler/build/x.php:1\n");
        $this->assertSame($sig1, $sig2);
    }

    public function testSeedCorpusCountFileMatches(): void
    {
        $dir = self::$root.'/test/differential/cases/fuzz';
        $count = (int) trim((string) file_get_contents($dir.'/COUNT'));
        $cases = glob($dir.'/seed_*.php') ?: [];
        $this->assertSame($count, count($cases));
        $this->assertGreaterThanOrEqual(13, $count);
    }

    public function testReducerShrinksRedundantEcho(): void
    {
        // A program that mismatches VM is hard to synthesize in-unit; instead assert the
        // reducer CLI exists and refuses a matching program (oracle not interesting → exit 1).
        $src = <<<'PHP'
<?php
declare(strict_types=1);
echo "ok\n";
PHP;
        $path = self::$root.'/build/fuzz-reduce-match.php';
        file_put_contents($path, $src);
        $cmd = 'cd '.escapeshellarg(self::$root)
            .' && php script/fuzz/reduce.php --in '.escapeshellarg($path)
            .' --backend vm --out '.escapeshellarg($path.'.out').' 2>&1';
        exec($cmd, $lines, $rc);
        $this->assertSame(1, $rc, implode("\n", $lines));
        $this->assertStringContainsString('does not reproduce', implode("\n", $lines));
    }

    public function testDdminReducerHitsFifteenLineBudget(): void
    {
        // Synthetic oracle: interesting iff source still contains both MARKER and NEEDLE.
        // Noise lines must be dropped; Done-when targets ≤15 nonempty lines for ≥80% of failures.
        $noise = [];
        for ($i = 0; $i < 40; ++$i) {
            $noise[] = '// noise '.$i;
            $noise[] = '$n'.$i.' = '.$i.';';
        }
        $src = "<?php\n\ndeclare(strict_types=1);\n\n"
            ."// @fuzz-seed: 0\n"
            ."// @fuzz-shape: synthetic\n\n"
            .implode("\n", $noise)."\n"
            ."\$marker = 'MARKER';\n"
            ."echo 'NEEDLE';\n"
            ."echo \$marker, \"\\n\";\n";

        $interesting = static function (string $s): bool {
            return str_contains($s, 'MARKER') && str_contains($s, 'NEEDLE');
        };
        $this->assertTrue($interesting($src));

        $reduced = fuzz_reduce_source($src, $interesting);
        $this->assertTrue($interesting($reduced));
        $nonempty = fuzz_count_nonempty_lines($reduced);
        $this->assertLessThanOrEqual(15, $nonempty, $reduced);
        $this->assertStringContainsString('MARKER', $reduced);
        $this->assertStringContainsString('NEEDLE', $reduced);
    }

    public function testNightlyScriptExists(): void
    {
        $path = self::$root.'/script/fuzz/nightly.sh';
        $this->assertFileExists($path);
        $this->assertTrue(is_executable($path), 'nightly.sh must be executable');
    }

    public function testFileSignaturesScriptDraftsIssueBody(): void
    {
        $failDir = self::$root.'/build/fuzz-file-sig-unit';
        if (is_dir($failDir)) {
            foreach (glob($failDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
        } else {
            mkdir($failDir, 0777, true);
        }
        $outdir = $failDir.'/drafts';
        $registry = $failDir.'/SIGNATURES.json';
        $sig = fuzz_normalize_signature('vm_diff', 0, 1, "ok\n", "bad\n");
        $src = "<?php\ndeclare(strict_types=1);\necho \"bad\\n\";\n";
        file_put_contents($failDir.'/vm_diff_seed99.php', $src);
        file_put_contents($failDir.'/vm_diff_seed99.json', json_encode([
            'seed' => 99,
            'kind' => 'vm_diff',
            'signature' => $sig,
            'zend_rc' => 0,
            'got_rc' => 1,
            'zend_out' => "ok\n",
            'got_out' => "bad\n",
        ], JSON_PRETTY_PRINT));

        $cmd = 'cd '.escapeshellarg(self::$root)
            .' && php script/fuzz/file-signatures.php'
            .' --failures-dir '.escapeshellarg($failDir)
            .' --outdir '.escapeshellarg($outdir)
            .' --registry '.escapeshellarg($registry)
            .' --limit 5 2>&1';
        exec($cmd, $lines, $rc);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $rc, $joined);
        $this->assertFileExists($outdir.'/vm_diff_seed99.md');
        $md = (string) file_get_contents($outdir.'/vm_diff_seed99.md');
        $this->assertStringContainsString('Fuzz:', $md);
        $this->assertStringContainsString($sig, $md);
        $this->assertStringContainsString('Part of #36398', $md);
        $reg = json_decode((string) file_get_contents($registry), true);
        $this->assertIsArray($reg);
        $this->assertArrayHasKey($sig, $reg['signatures']);

        // Second run must skip the known signature.
        $lines2 = [];
        exec($cmd, $lines2, $rc2);
        $joined2 = implode("\n", $lines2);
        $this->assertSame(0, $rc2, $joined2);
        $this->assertStringContainsString('"skipped_known": 1', $joined2);
        $this->assertStringContainsString('"processed": 0', $joined2);
    }

    public function testEdgeShapesAreRegistered(): void
    {
        $shapes = fuzz_known_shapes();
        $this->assertContains('strlen_after_concat_guard', $shapes);
        $this->assertContains('assoc_string_keys', $shapes);
        $this->assertContains('foreach_byref_mutate', $shapes);
        $this->assertContains('string_offset_assign', $shapes);
        $this->assertContains('switch_int_fallthrough', $shapes);
        $this->assertContains('static_counter_fn', $shapes);
        $this->assertContains('array_plus_vs_merge', $shapes);
        $this->assertContains('new_static_counter_ctor_args', $shapes);
        $this->assertGreaterThanOrEqual(17, count($shapes));
    }

    public function testCoverageBiasWeightsUndercoveredShapesHigher(): void
    {
        $weights = fuzz_shape_coverage_weights();
        $this->assertArrayHasKey('new_static_counter_ctor_args', $weights);
        $this->assertArrayHasKey('arith_main', $weights);
        // Seed corpus tags @fuzz-shape on committed seeds — under-covered shapes
        // (including brand-new ones) must outrank heavily seeded shapes.
        $this->assertGreaterThanOrEqual(
            $weights['arith_main'],
            $weights['new_static_counter_ctor_args']
        );
        $picked = [];
        for ($seed = 1; $seed <= 200; ++$seed) {
            $src = fuzz_generate_program($seed, 'coverage');
            if (preg_match('/@fuzz-shape:\s*(\S+)/', $src, $m) === 1) {
                $picked[$m[1]] = ($picked[$m[1]] ?? 0) + 1;
            }
        }
        $this->assertNotEmpty($picked);
        $this->assertArrayHasKey('new_static_counter_ctor_args', $picked);
    }

    public function testDefaultSweepScriptIncludesFuzzCorpus(): void
    {
        $path = self::$root.'/script/differential-sweep.sh';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('cases/fuzz', $src);
        $this->assertStringContainsString('fuzz/COUNT', $src);
        $this->assertStringContainsString('#36398', $src);
    }
}
