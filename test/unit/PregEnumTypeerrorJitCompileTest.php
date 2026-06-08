<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint verify for preg_quote()/preg_split() enum-case TypeError guards (#5999).
 *
 * php-src: ext/pcre/php_pcre.c — php_pcre_quote, php_pcre_split
 */
final class PregEnumTypeerrorJitCompileTest extends TestCase
{
    /**
     * @dataProvider compileOnlyFixtureProvider
     */
    public function testPregEnumCaseTypeErrorAotLint(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.$relativePath;
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$relativePath
        );
    }

    /**
     * @return list<list<string>>
     */
    public static function compileOnlyFixtureProvider(): array
    {
        return [
            ['test/fixtures/aot/compile-only/preg_quote_enum_typeerror.php'],
            ['test/fixtures/aot/compile-only/preg_split_enum_typeerror.php'],
        ];
    }
}
