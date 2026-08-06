<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6750 / #9444 / #13358 / #27180: CSV standalone AOT routes through NestedJIT-safe helpers.
 *
 * @group aot-lint
 */
final class StringFgetcsvRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesStringStrGetcsvJitMonolith(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringStrGetcsvJit.php');

        $strGetcsv = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringStrGetcsv.php');
        $this->assertStringContainsString('CsvStrGetcsvJitHelper', $strGetcsv);
        $this->assertStringNotContainsString('StringStrGetcsvJit', $strGetcsv);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $strGetcsv);

        $fgetcsv = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringFgetcsvJit.php');
        $this->assertStringContainsString('CsvStrGetcsvJitHelper::strGetcsvArgv', $fgetcsv);
        $this->assertStringContainsString('__compiler_fgets', $fgetcsv);
        $this->assertStringNotContainsString('CsvJitHelper::fgetcsvArgv', $fgetcsv);
        $this->assertStringNotContainsString('__phpc_csv_parse_line', $fgetcsv);
    }
}
