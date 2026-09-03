<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {main} `$buf .= …` must grow via appendInPlace, not alloc+memcpy both halves (#36386 / #36410).
 *
 * php-src: Zend/zend_operators.c zend_string_extend / ZEND_ASSIGN_OP (CONCAT).
 *
 * @group aot-lint
 */
final class MainScriptInPlaceAppendAotTest extends TestCase
{
    public function testMainScriptAppendUsesReallocNotFullAlloc(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 3; ++$i) {
            $buf .= 'x';
        }
        echo strlen($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_inplace_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_inplace_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $fnStart = strpos($ll, 'define void @internal_');
            $this->assertNotFalse($fnStart, 'missing @internal_* {main} body');
            // Prefer the largest internal_* (user script), not a tiny stub.
            $bestStart = $fnStart;
            $bestLen = 0;
            $offset = 0;
            while (false !== ($s = strpos($ll, 'define void @internal_', $offset))) {
                $e = strpos($ll, "\ndefine ", $s + 1);
                $len = false === $e ? strlen($ll) - $s : $e - $s;
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestStart = $s;
                }
                $offset = $s + 1;
            }
            $fnEnd = strpos($ll, "\ndefine ", $bestStart + 1);
            $body = false === $fnEnd ? substr($ll, $bestStart) : substr($ll, $bestStart, $fnEnd - $bestStart);
            $this->assertMatchesRegularExpression(
                '/call void @__string__realloc\(/',
                $body,
                '{main} $buf .= must use __string__realloc (#36386)'
            );
            $this->assertStringContainsString('append_', $body, 'expected appendInPlace blocks');
            $reallocStart = strpos($ll, 'define void @__string__realloc');
            $this->assertNotFalse($reallocStart, 'missing @__string__realloc');
            $reallocEnd = strpos($ll, "\ndefine ", $reallocStart + 1);
            $reallocBody = false === $reallocEnd
                ? substr($ll, $reallocStart)
                : substr($ll, $reallocStart, $reallocEnd - $reallocStart);
            $this->assertStringContainsString(
                'malloc_usable_size',
                $reallocBody,
                '__string__realloc must early-out via malloc_usable_size (#36386)'
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['3'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMainScriptSeededAppendKeepsPrefix(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = 'ab';
        $buf .= 'cd';
        echo $buf, '|', strlen($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_seed_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_seed_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['abcd|4'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMainScriptIntInterpAppendMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 5; ++$i) {
            $buf .= 'row-'.$i.';';
        }
        echo strlen($buf), '|', $buf, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_int_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_int_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMainScriptDynamicAppendUsesReallocNotFullAlloc(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 4; ++$i) {
            $buf .= 'row-'.$i.';';
        }
        echo strlen($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_dyn_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_dyn_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $bestStart = 0;
            $bestLen = 0;
            $offset = 0;
            while (false !== ($s = strpos($ll, 'define void @internal_', $offset))) {
                $e = strpos($ll, "\ndefine ", $s + 1);
                $len = false === $e ? strlen($ll) - $s : $e - $s;
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestStart = $s;
                }
                $offset = $s + 1;
            }
            $this->assertGreaterThan(0, $bestLen, 'missing @internal_* {main} body');
            $fnEnd = strpos($ll, "\ndefine ", $bestStart + 1);
            $body = false === $fnEnd ? substr($ll, $bestStart) : substr($ll, $bestStart, $fnEnd - $bestStart);
            $this->assertMatchesRegularExpression(
                '/call void @__string__realloc\(/',
                $body,
                '{main} $buf .= dynamic must use __string__realloc (#36386)'
            );
            $this->assertStringContainsString('append_', $body, 'expected appendInPlace blocks');
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['24'], $runOut); // strlen('row-0;row-1;row-2;row-3;') = 24
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMainScriptDynamicAppendMatchesZendAtScale(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 300; ++$i) {
            $buf .= 'x';
        }
        if (strlen($buf) > 1000) {
            $buf = substr($buf, 500);
        }
        echo strlen($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_scale_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_scale_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
            $this->assertSame(['300'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMainScriptHundredThousandAppendsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 100000; ++$i) {
            $buf .= 'x';
        }
        echo strlen($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_100k_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_100k_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
            $this->assertSame(['100000'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    /**
     * {main} `$buf .= "row-".$i` used to free(): invalid pointer past ~170 iterations —
     * destSlot shared the buffer with the script-global value box across realloc (#36386).
     */
    public function testMainScriptInterpConcatAppendMatchesZendPastHeapThreshold(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 250; ++$i) {
            $buf .= 'row-'.$i.';';
        }
        echo strlen($buf), '|', md5($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_main_interp_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_main_interp_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            for ($r = 0; $r < 5; ++$r) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, "run $r: ".implode("\n", $runOut));
                $this->assertSame($zendOut, $runOut, "run $r output mismatch");
            }
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testFunctionLocalDynamicAppendMatchesZendAtScale(): void
    {
        $src = <<<'PHP'
        <?php
        function build(int $n): string {
            $buf = '';
            for ($i = 0; $i < $n; ++$i) {
                $buf .= 'row-'.$i.';';
            }
            return $buf;
        }
        $s = build(30);
        echo strlen($s), '|', substr($s, 0, 12), '|', substr($s, 194, 6), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_fn_dyn_append_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fn_dyn_append_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
