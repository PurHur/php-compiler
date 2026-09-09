<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Thin-AOT preg_match / preg_match_all literals must match Zend without SIGSEGV (#36385).
 *
 * php-src: ext/pcre/php_pcre.c php_pcre_match_impl
 */
final class PregMatchLiteralLongArith36385AotTest extends \PHPUnit\Framework\TestCase
{
    public function testPregMatchAndMatchAllLiteralsMatchZendOnVmAndAot(): void
    {
        $path = __DIR__ . '/../repro/preg_match_literal_long_arith_36385.php';
        $zend = $this->runZendFile($path);
        $this->assertSame("1|0|1|2\n2|2|2|\n", $zend);

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile($path));
        $this->assertSame($zend, ob_get_clean(), 'VM must match Zend');

        $this->assertSame($zend, $this->compileAndRunAot($path), 'AOT must match Zend');
    }

    private function runZendFile(string $path): string
    {
        $php = getenv('PHP_8_2') ?: (getenv('PHP_BINARY') ?: PHP_BINARY);
        $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1');

        return is_string($out) ? $out : '';
    }

    private function compileAndRunAot(string $path): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'aot36385_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = 'php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/compile.php')
            . ' -o ' . escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' 2>&1';
        exec($compile, $clog, $crc);
        $this->assertSame(0, $crc, "compile failed:\n" . implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "aot run failed:\n" . implode("\n", $out));

            return implode("\n", $out) . ([] !== $out ? "\n" : '');
        } finally {
            @unlink($bin);
        }
    }
}
