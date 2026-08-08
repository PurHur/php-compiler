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
        $this->assertStringContainsString('CsvStrGetcsvJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::coerceToHashtablePtr', $source);
        $this->assertStringContainsString('constantStringFromString', $source);
        $this->assertStringNotContainsString('StringStrGetcsvJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        // #27069 — raw cstr globals are not __string__* for __string__separate
        $this->assertStringNotContainsString('constantFromString($default)', $source);
        // Do not NestedJIT CsvJitHelper.php (pulls VmFs via fgetcsvArgv).
        $this->assertStringNotContainsString('/ext/standard/CsvJitHelper.php', $source);
    }

    public function testStringFgetcsvJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFgetcsvJit.php');
        $this->assertStringContainsString('CsvStrGetcsvJitHelper::strGetcsvArgv', $source);
        $this->assertStringContainsString('__compiler_fgets', $source);
        $this->assertStringContainsString('implementFgetcsvBridge', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::coerceToHashtablePtr', $source);
        // #27180 — do not NestedJIT CsvJitHelper fgetcsvArgv / VmFs under thin AOT.
        $this->assertStringNotContainsString('CsvJitHelper::fgetcsvArgv', $source);
        $this->assertStringNotContainsString('/ext/standard/CsvJitHelper.php', $source);
        $this->assertStringNotContainsString('/ext/standard/VmFs.php', $source);
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

    public function testCsvStrGetcsvJitHelperMatchesVmCsv(): void
    {
        $fields = \PHPCompiler\ext\standard\VmCsv::parseLine('a,"b,c",d');
        $row = \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv('a,"b,c",d', ',', '"', '\\');
        $this->assertSame($fields, $row);
    }

    public function testCsvStrGetcsvJitHelperStripsTrailingRecordTerminator(): void
    {
        // #28994 — trailing LF/CRLF must not remain in the last field (php-src / VmCsv).
        foreach (["a,b\n", "a,b\r\n", "a,\n", "a,\"b\nc\"\n"] as $input) {
            $this->assertSame(
                \PHPCompiler\ext\standard\VmCsv::parseLine($input),
                \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv($input, ',', '"', '\\'),
                'mismatch for '.json_encode($input)
            );
        }
        $this->assertSame(
            ['a', 'b'],
            \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv("a,b\n", ',', '"', '\\')
        );
        $this->assertSame(
            ['a', "b\nc"],
            \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv("a,\"b\nc\"\n", ',', '"', '\\')
        );
    }

    public function testCsvStrGetcsvJitHelperEmptySignalsNullRow(): void
    {
        // NestedJIT cannot materialize [null]; bridge expands [] → [null] (#27069).
        $this->assertSame([], \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv('', ',', '"', '\\'));
        $this->assertSame([], \PHPCompiler\ext\standard\CsvStrGetcsvJitHelper::strGetcsvArgv("\n", ',', '"', '\\'));
    }

    public function testJitStrGetcsvDoesNotPullStringStreamCsv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrGetcsv.php');
        $this->assertStringContainsString('StringStrGetcsv::ensureLinked', $source);
        $this->assertStringNotContainsString('StringStreamCsv', $source);
    }

    public function testSpineBundleIncludesCsvStrGetcsvJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CsvJitHelper.php', $spine);
        $this->assertStringContainsString('CsvStrGetcsvJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrGetcsv.php', $spine);
        $this->assertStringNotContainsString('StringStrGetcsvJit.php', $spine);
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
        $field = \PHPCompiler\ext\standard\CsvFputcsvJitHelper::formatFieldArgv('a\b', ',', '"', '\\');
        $this->assertSame('"a\b"', $field);
    }

    public function testCsvFputcsvJitHelperMatchesVmCsvFormatField(): void
    {
        $cases = [
            ['a', 'a'],
            ['b,c', '"b,c"'],
            ['say "hi"', '"say ""hi"""'],
            ["x\ny", "\"x\ny\""],
            ['a b', '"a b"'],
            ["a\tb", "\"a\tb\""],
        ];
        foreach ($cases as [$in, $want]) {
            $this->assertSame(
                $want,
                \PHPCompiler\ext\standard\CsvFputcsvJitHelper::formatFieldArgv($in, ',', '"', '\\'),
                'field='.json_encode($in)
            );
        }
        $this->assertSame(
            \PHPCompiler\ext\standard\VmCsv::formatLine(['a', 'b,c', 'd']),
            \PHPCompiler\ext\standard\CsvFputcsvJitHelper::formatFieldArgv('a', ',', '"', '\\')
                .','
                .\PHPCompiler\ext\standard\CsvFputcsvJitHelper::formatFieldArgv('b,c', ',', '"', '\\')
                .','
                .\PHPCompiler\ext\standard\CsvFputcsvJitHelper::formatFieldArgv('d', ',', '"', '\\')
        );
    }

    public function testSpineBundleIncludesCsvFputcsvJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CsvFputcsvJitHelper.php', $spine);
        $this->assertStringContainsString('CsvStrGetcsvJitHelper.php', $spine);
    }

    public function testFputcsvRuntimeUsesCsvFputcsvJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FputcsvRuntime.php');
        $this->assertStringContainsString('CsvFputcsvJitHelper', $source);
        $this->assertStringContainsString('formatFieldArgv', $source);
        $this->assertStringContainsString('HashTableHelper::readIndexedToValueBox', $source);
        $this->assertStringNotContainsString('CsvJitHelper::formatFieldsArgv', $source);
        $this->assertStringNotContainsString('/ext/standard/CsvJitHelper.php', $source);
    }

    public function testSpineBundleIncludesCsvJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CsvJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrGetcsv.php', $spine);
        $this->assertStringNotContainsString('StringStrGetcsvJit.php', $spine);
    }
}
