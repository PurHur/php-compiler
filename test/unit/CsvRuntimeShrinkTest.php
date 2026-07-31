<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CsvJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * str_getcsv/fgetcsv JIT routes through CsvJitHelper PHP (#9444, #13358, #26135).
 */
final class CsvRuntimeShrinkTest extends TestCase
{
    public function testStringStrGetcsvJitMonolithDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStrGetcsvJit.php');
    }

    public function testStringStrGetcsvUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrGetcsv.php');
        $this->assertStringContainsString('CsvJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('StringStrGetcsvJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testStringFgetcsvJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFgetcsvJit.php');
        $this->assertStringContainsString('CsvJitHelper::fgetcsvArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('implementFgetcsvBridge', $source);
        $this->assertStringNotContainsString('emitCompilerFgetcsvPhpParse', $source);
        $this->assertStringNotContainsString('lookupFunction(\'fgets\')', $source);
        $this->assertStringNotContainsString('lookupFunction(\'malloc\')', $source);
        $this->assertStringNotContainsString('__phpc_resolve_stream', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testCsvJitHelperFgetcsvArgvMatchesVmFs(): void
    {
        $handle = \PHPCompiler\ext\standard\VmPhpMemoryStream::open('php://memory', 'w+b');
        $this->assertIsInt($handle);
        \PHPCompiler\ext\standard\VmPhpMemoryStream::write($handle, "a,b\n");
        \PHPCompiler\ext\standard\VmPhpMemoryStream::seek($handle, 0, \SEEK_SET);
        $ht = CsvJitHelper::fgetcsvArgv($handle, -1, ',', '"', '\\');
        $this->assertNotNull($ht);
        $this->assertSame(2, $ht->getNumElements());
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

    /** Issue #18592 — unterminated empty enclosure yields NUL byte via shared VmCsv SSOT. */
    public function testCsvJitHelperLoneQuoteNulByteField(): void
    {
        $fields = \PHPCompiler\ext\standard\VmCsv::parseLine('"');
        $this->assertSame(["\0"], $fields);
        $ht = CsvJitHelper::strGetcsvArgv('"', ',', '"', '\\');
        $rebuilt = [];
        foreach ($ht->iterate() as $cell) {
            $rebuilt[] = $cell->toString();
        }
        $this->assertSame($fields, $rebuilt);
    }

    public function testFputcsvFormatLineDoesNotDoubleBackslashEscape(): void
    {
        $line = \PHPCompiler\ext\standard\VmCsv::formatLine(['a\b'], ',', '"', '\\');
        $this->assertSame('"a\b"', $line);
        $ht = new \PHPCompiler\VM\HashTable();
        $cell = new \PHPCompiler\VM\Variable();
        $cell->string('a\b');
        $ht->append($cell);
        $jitLine = CsvJitHelper::formatFieldsArgv($ht, ',', '"', '\\');
        $this->assertSame($line, $jitLine);
    }

    public function testSpineBundleIncludesCsvJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CsvJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrGetcsv.php', $spine);
        $this->assertStringNotContainsString('StringStrGetcsvJit.php', $spine);
    }
}
