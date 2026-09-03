<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Property stores must not emit a per-site class_id switch for readonly($obj) (#36386).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property
 *
 * @group aot-lint
 */
final class DynReadonlyGuardCompactAotTest extends TestCase
{
    public function testTypedPropertyIncAvoidsPerSiteClassIdSwitch(): void
    {
        $src = <<<'PHP'
        <?php
        final class Node {
            public int $value;
            public function __construct(int $value) { $this->value = $value; }
            public function bump(): int {
                ++$this->value;
                return $this->value;
            }
        }
        $n = new Node(1);
        echo $n->bump(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_dyn_ro_compact_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_dyn_ro_compact_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' --no-cache -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $llLines = explode("\n", (string) file_get_contents('/tmp/phpc-last.ll'));
            $start = null;
            foreach ($llLines as $i => $line) {
                if (str_starts_with($line, 'define i64 @Node__bump(')) {
                    $start = $i;
                    break;
                }
            }
            $this->assertNotNull($start, 'missing @Node__bump');
            $end = $start + 1;
            while ($end < \count($llLines) && !str_starts_with($llLines[$end], 'define ')) {
                ++$end;
            }
            $body = implode("\n", \array_slice($llLines, $start, $end - $start));
            $this->assertLessThan(200, $end - $start, 'Node__bump IR lines='.($end - $start));
            // Known-class cold path: one reject block, not a linear class_id ladder.
            $this->assertStringContainsString('dyn_readonly_reject', $body);
            $this->assertStringNotContainsString('dyn_readonly_match_', $body);
            $this->assertStringNotContainsString('dyn_readonly_try_', $body);
            $this->assertLessThan(
                20,
                substr_count($body, '__compiler_jit_raise_error'),
                'bump must not embed one raise_error arm per registered class'
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['2'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }
}
