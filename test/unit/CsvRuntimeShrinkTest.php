<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CsvJitHelper;
use PHPUnit\Framework\TestCase;

/** str_getcsv/fgetcsv JIT routes through CsvJitHelper PHP, not StringStrGetcsvJit LLVM (#9444, #13358). */
final class CsvRuntimeShrinkTest extends TestCase
{
    public function testStringStrGetcsvJitMonolithDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStrGetcsvJit.php');
    }

    public function testStringStrGetcsvUsesJitHelperForAllLoadTypes(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrGetcsv.php');
        $this->assertStringContainsString('CsvJitHelper', $source);
        $this->assertStringNotContainsString('StringStrGetcsvJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }

    public function testStringFgetcsvJitUsesPhpParseOnStandalone(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFgetcsvJit.php');
        $this->assertStringContainsString('emitCompilerFgetcsvPhpParse', $source);
        $this->assertStringContainsString('CsvJitHelper::parseLineArgv', $source);
        $this->assertStringNotContainsString('emitCompilerFgetcsv(', $source);
        $this->assertStringNotContainsString('__phpc_csv_parse_line', $source);
    }

    public function testCsvJitHelperMatchesVmCsvSemantics(): void
    {
        $fields = \PHPCompiler\ext\standard\VmCsv::parseLine('a,b,c');
        $ht = CsvJitHelper::strGetcsvArgv('a,b,c', ',', '"', '\\');
        $this->assertSame(\count($fields), $ht->getNumElements());
        $rebuilt = [];
        foreach ($ht->iterate() as $cell) {
            $rebuilt[] = \PHPCompiler\VM\Variable::TYPE_NULL === $cell->type ? null : $cell->toString();
        }
        $this->assertSame($fields, $rebuilt);
    }

    public function testSpineBundleIncludesCsvJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CsvJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrGetcsv.php', $spine);
        $this->assertStringNotContainsString('StringStrGetcsvJit.php', $spine);
    }
}
