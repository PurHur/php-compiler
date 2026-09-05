<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Weekly status report renderer (#36404).
 */
final class StatusWeeklyReportTest extends TestCase
{
    private string $lib;

    protected function setUp(): void
    {
        $this->lib = dirname(__DIR__, 2).'/script/status/report-lib.php';
        require_once $this->lib;
    }

    public function testFormatDelta(): void
    {
        $this->assertSame('n/a', status_report_format_delta(null));
        $this->assertSame('0', status_report_format_delta(0));
        $this->assertSame('+3', status_report_format_delta(3));
        $this->assertSame('-2', status_report_format_delta(-2));
    }

    public function testMetricRowsComputeDeltas(): void
    {
        $current = [
            'spine' => 8213,
            'inventory' => 8213,
            'builtins_matrix_rows' => 4054,
            'builtins_vm_yes' => 4021,
            'builtins_jit_yes' => 1883,
            'builtins_aot_yes' => 1737,
            'differential_cases' => 321,
            'apps_ready' => 1,
            'apps_packages' => 12,
            'ci_green_streak_days' => 2,
        ];
        $previous = $current;
        $previous['spine'] = 8210;
        $previous['ci_green_streak_days'] = 1;
        $rows = status_report_metric_rows($current, $previous);
        $this->assertSame(8213, $rows['Spine units']['current']);
        $this->assertSame(8210, $rows['Spine units']['previous']);
        $this->assertSame(3, $rows['Spine units']['delta']);
        $this->assertSame(1, $rows['Local CI streak (days)']['delta']);
    }

    public function testRenderMarkdownIncludesTableAndTracker(): void
    {
        $snapshot = [
            'generated_at' => '2026-09-05T03:00:00Z',
            'spine' => 8213,
            'inventory' => 8213,
            'builtins_matrix_rows' => 4054,
            'builtins_vm_yes' => 4021,
            'builtins_jit_yes' => 1883,
            'builtins_aot_yes' => 1737,
            'differential_cases' => 321,
            'apps_ready' => 1,
            'apps_packages' => 12,
            'ci_green_streak_days' => 2,
            'last_green_master_sha' => 'abcdef0123456789deadbeef',
        ];
        $p0 = [
            ['number' => 36143, 'title' => 'patch drift', 'labels' => ['release-blocker']],
        ];
        $md = status_report_render_markdown($snapshot, null, '2026-09-05', $p0, [], '36379');
        $this->assertStringContainsString('# php-compiler weekly status — 2026-09-05', $md);
        $this->assertStringContainsString('| Spine units | 8213 |', $md);
        $this->assertStringContainsString('#36379', $md);
        $this->assertStringContainsString('#36143', $md);
        $this->assertStringContainsString('abcdef012345', $md);
        $this->assertStringContainsString('local-CI-only', $md);
    }

    public function testLoadPreviousMetricsFromCommentFence(): void
    {
        $dir = sys_get_temp_dir().'/phpc-status-report-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0775, true));
        $prior = $dir.'/2026-08-29.md';
        $body = "# old\n\n".status_report_metrics_comment([
            'spine' => 8200,
            'inventory' => 8200,
            'ci_green_streak_days' => 1,
        ])."\n";
        file_put_contents($prior, $body);
        $loaded = status_report_load_previous_metrics($dir, '2026-09-05');
        $this->assertIsArray($loaded);
        $this->assertSame(8200, $loaded['spine']);
        $this->assertSame(1, $loaded['ci_green_streak_days']);
        // cleanup
        unlink($prior);
        rmdir($dir);
    }

    public function testCliWritesLatestAndDated(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/status/report.php')
            .' --date=2099-01-01 --stdout 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $text = implode("\n", $out);
        $this->assertStringContainsString('weekly status — 2099-01-01', $text);
        $this->assertStringContainsString('status-report-metrics:', $text);
    }
}
