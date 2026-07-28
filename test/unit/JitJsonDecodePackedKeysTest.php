<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_decode() list elements must be packed int keys so `$d['b'][1]` matches Zend (#24116).
 *
 * php-src: ext/json/php_json.c — php_json_decode_ex (JSON arrays → PHP packed HT).
 */
final class JitJsonDecodePackedKeysTest extends TestCase
{
    public function testAssocMaterializeUsesPackedIndexStores(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonDecode.php');
        $this->assertStringContainsString('storeIndexValueAssoc', $src);
        $this->assertStringContainsString('storeStringKeyValueAssoc', $src);
        $this->assertStringContainsString('#24116', $src);
        $this->assertMatchesRegularExpression(
            '/function buildHashtableFromPhp[\s\S]*is_int\(\$key\)[\s\S]*storeIndexValueAssoc/',
            $src
        );
        $this->assertMatchesRegularExpression(
            '/function storeIndexValueAssoc[\s\S]*__hashtable__setHashtableAt[\s\S]*__hashtable__setLongAt/',
            $src
        );
    }

    public function testNestedJsonDecodeDimFetchAotMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_24116_json_decode_nested_dim.php';
        $expected = "3\n";

        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vmOut, 'VM nested json_decode dim must match Zend');

        $out = $root.'/build/test-aot-json-decode-nested-dim-24116';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aotOut, 'AOT nested json_decode dim must match Zend');
    }

    public function testRootJsonListIntIndexAotMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_24116_json_decode_list_int_index.php';
        $expected = "2\n3\n";

        $out = $root.'/build/test-aot-json-decode-list-int-24116';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $this->assertSame($expected, $this->runCommand([$out], $root));
        $this->assertSame($expected, $this->runCommand([PHP_BINARY, $source], $root));
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
