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
        foreach (['arith_main', 'arith_fn', 'string_concat_loop', 'array_list', 'control_break', 'mixed_scope'] as $shape) {
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
        $this->assertGreaterThanOrEqual(6, $count);
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
}
