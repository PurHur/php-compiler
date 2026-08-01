<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Typed non-void missing return raises TypeError with Zend "none returned" (#26486).
 */
final class TypedMissingReturnTypeErrorTest extends TestCase
{
    public function testVmReproMatchesZendTypeError(): void
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        $repro = realpath(__DIR__.'/../repro/maintainer_gap_typed_missing_return_typeerror.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);
        $cmd = [PHP_BINARY, $bin, $repro];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, $out);
        $this->assertSame(
            "TypeError:f(): Return value must be of type int, none returned\n",
            $out
        );
    }
}
