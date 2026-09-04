<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM: `$this->prop['lit'] = $localArray` must persist (#36380 Parsedown references).
 *
 * php-src: Zend/zend_execute.c zend_fetch_dimension_address (IS_CONST dim keys).
 */
final class PropDimLiteralKeyArray36380Test extends TestCase
{
    public function testLiteralKeyLocalArrayAssignMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/nested_prop_dim_locals_36380.php';
        $expected = "locals=1\nurl=http://example.com\ninline=1\n";

        $zend = $this->runCommand([PHP_BINARY, $source], $root);
        $this->assertSame($expected, $zend, 'Zend reference');

        $vm = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vm, 'VM nested prop dim with literal key + array RHS');
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
