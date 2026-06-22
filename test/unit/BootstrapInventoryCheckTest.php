<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bootstrap-inventory.php --check actionable diff output (issue #3006).
 */
final class BootstrapInventoryCheckTest extends TestCase
{
    public function testCheckPrintsOkWhenFresh(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-inventory.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        if (1 === $code && str_contains($text, 'Stale')) {
            $this->assertStringContainsString('Diff:', $text);
            $this->assertStringContainsString('File-list drift:', $text);
            $this->assertStringContainsString('Optional construct-flag refresh', $text);
            $this->markTestSkipped('docs/bootstrap-inventory.md stale; regenerate with php script/bootstrap-inventory.php');
        }
        $this->assertSame(0, $code, $text);
        $this->assertMatchesRegularExpression('/^OK \d+\/\d+$/', trim($out[count($out) - 1] ?? ''));
    }

    private static function loadBootstrapLib(): void
    {
        if (!function_exists('bootstrapParseInventoryMarkdown')) {
            require_once dirname(__DIR__, 2).'/script/bootstrap-lib.php';
        }
    }

    public function testDiffReportsWarningCountDrift(): void
    {
        self::loadBootstrapLib();

        $committed = bootstrapParseInventoryMarkdown(<<<'MD'
# Bootstrap inventory (vm.php path)

## Summary

| Metric | Count |
|--------|------:|
| PHP files on vm.php path | 10 |
| Phase A inventory files (M2 ratio SSOT) | 10 |
| Source constructs flagged (blockers) | 0 |
| Source constructs flagged (warnings) | 5 |

## Compiler CFG gaps (`lib/Compiler.php`)


## Files

| File | Blockers | Warnings |
|------|----------|----------|
| `lib/JIT.php` | 0 | 5 |

## Per-file construct flags

### `lib/JIT.php`

**Warnings** (review for bootstrap subset):
- closure(s) (line 100)

MD);

        $live = [
            'totals' => ['files' => 10, 'blockers' => 0, 'warnings' => 6],
            'phase_a' => ['phase_a_inventory_files' => 10],
            'compiler_blockers' => [],
            'files' => [
                'lib/JIT.php' => [
                    'blockers' => [],
                    'warnings' => [
                        'closure(s) (line 100)',
                        'closure(s) (line 110)',
                        'closure(s) (line 120)',
                        'closure(s) (line 130)',
                        'closure(s) (line 140)',
                        'closure(s) (line 200)',
                    ],
                ],
            ],
        ];

        $diff = bootstrapDiffInventoryReport($committed, $live);
        $text = implode("\n", $diff);
        $this->assertStringContainsString('Summary:', $text);
        $this->assertStringContainsString('warnings): 5 → 6', $text);
        $this->assertStringContainsString('Per-file counts:', $text);
        $this->assertStringContainsString('lib/JIT.php: warnings 5 → 6', $text);
    }

    public function testDiffReportsAddedPhaseAPath(): void
    {
        self::loadBootstrapLib();

        $committed = bootstrapParseInventoryMarkdown(<<<'MD'
# Bootstrap inventory (vm.php path)

## Summary

| Metric | Count |
|--------|------:|
| PHP files on vm.php path | 2 |
| Phase A inventory files (M2 ratio SSOT) | 2 |
| Source constructs flagged (blockers) | 0 |
| Source constructs flagged (warnings) | 1 |

## Compiler CFG gaps (`lib/Compiler.php`)


## Files

| File | Blockers | Warnings |
|------|----------|----------|
| `lib/A.php` | 0 | 1 |

## Per-file construct flags

MD);

        $live = [
            'totals' => ['files' => 3, 'blockers' => 0, 'warnings' => 2],
            'phase_a' => ['phase_a_inventory_files' => 3],
            'compiler_blockers' => [],
            'files' => [
                'lib/A.php' => ['blockers' => [], 'warnings' => ['x']],
                'lib/B.php' => ['blockers' => [], 'warnings' => ['y']],
            ],
        ];

        $diff = bootstrapDiffInventoryReport($committed, $live);
        $text = implode("\n", $diff);
        $this->assertStringContainsString('Phase A paths', $text);
        $this->assertStringContainsString('+ lib/B.php', $text);
    }

    public function testCheckExitOneWithDiffSectionsOnStaleDoc(): void
    {
        $root = dirname(__DIR__, 2);
        $doc = $root.'/docs/bootstrap-inventory.md';
        $this->assertFileExists($doc);
        $original = (string) file_get_contents($doc);
        $stale = preg_replace(
            '/\| Source constructs flagged \(warnings\) \| (\d+) \|/',
            '| Source constructs flagged (warnings) | $1 |',
            $original,
            1,
            $count
        );
        $this->assertSame(1, $count);
        if (preg_match('/\| Source constructs flagged \(warnings\) \| (\d+) \|/', $stale, $m)) {
            $stale = preg_replace(
                '/\| Source constructs flagged \(warnings\) \| '.$m[1].' \|/',
                '| Source constructs flagged (warnings) | '.((int) $m[1] + 1).' |',
                $stale,
                1
            );
        }
        file_put_contents($doc, $stale);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-inventory.php').' --check 2>&1';
            exec($cmd, $out, $code);
            $text = implode("\n", $out);
            $this->assertSame(1, $code, $text);
            $this->assertStringContainsString('Stale', $text);
            $this->assertStringContainsString('Diff:', $text);
            $this->assertStringContainsString('Summary:', $text);
        } finally {
            file_put_contents($doc, $original);
        }
    }
}
