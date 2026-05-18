<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM edge cases for != and !== (issue #211).
 */
final class NotEqualOperatorsTest extends TestCase
{
    public function testStrictAndLooseInequality(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $file = tempnam(sys_get_temp_dir(), 'phpc_ne_');
        $this->assertNotFalse($file);
        file_put_contents($file, <<<'PHP'
<?php
echo (0 !== false) ? "1\n" : "0\n";
echo (null !== false) ? "1\n" : "0\n";
$method = 'POST';
echo ($method !== 'GET') ? "1\n" : "0\n";
PHP
        );
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', $bin, $file], $descriptorSpec, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($file);
        $this->assertSame("1\n1\n1\n", $out !== false ? $out : '');
    }
}
