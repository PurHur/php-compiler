<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_decode() top-level bool scalars must be IS_TRUE/IS_FALSE, not long (#26887).
 *
 * php-src: ext/json/php_json.c — php_json_decode_ex (JSON true/false → zend_bool).
 */
final class JitJsonDecodeScalarBoolTest extends TestCase
{
    public function testMaterializeScalarWritesBoolNotLong(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonDecode.php');
        $this->assertStringContainsString('#26887', $src);
        $this->assertStringContainsString('JitValueBox::writeBool($context, $slot, $context->constantFromBool($scalar));', $src);
        // Bool branch must not call writeLong before closing brace.
        $this->assertDoesNotMatchRegularExpression(
            '/if \(\\\\is_bool\(\$scalar\)\) \{[^}]*JitValueBox::writeLong/',
            $src
        );
    }

    public function testAotScalarAndAssocTypesMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_26887_json_decode_aot_scalars.php';
        $expected = "boolean T\nboolean F\nNULL N\ninteger 42\ndouble 3.14\nstring hi\narray 1\nobject 1\n";

        $this->assertSame(
            $expected,
            $this->runCommand([PHP_BINARY, $source], $root),
            'Zend host php must match expected'
        );
        $this->assertSame(
            $expected,
            $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root),
            'VM must match Zend'
        );

        $out = $root.'/build/test-aot-json-decode-scalar-types-26887';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $this->assertSame($expected, $this->runCommand([$out], $root), 'AOT must match Zend');
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd, int $expectExit = 0): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame($expectExit, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
