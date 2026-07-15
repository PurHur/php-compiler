<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\str_getcsv;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for str_getcsv(). */
final class StrGetcsvBuiltinTest extends TestCase
{
    public function testSimpleRow(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('a,b,c');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $this->assertSame(3, $ht->getNumElements());
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b', 'c'], $vals);
    }

    /** Issue #4318 — Z_PARAM_STR coerces int/float/bool operands (ext/standard/file.c). */
    public function testScalarCoercion(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();

        $intArg = new VMVariable();
        $intArg->int(123);
        $frame->calledArgs = [$intArg];
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['123'], $vals);

        $floatArg = new VMVariable();
        $floatArg->float(1.5);
        $frame->calledArgs = [$floatArg];
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['1.5'], $vals);

        $boolArg = new VMVariable();
        $boolArg->bool(true);
        $frame->calledArgs = [$boolArg];
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['1'], $vals);
    }

    public function testQuotedFields(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('"hello","world"');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['hello', 'world'], $vals);
    }

    /** Issue #9303 — escape=enclosure uses doubled-quote unescaping only (php-src file.c). */
    public function testEscapeEqualsEnclosureDoubledQuotes(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('a,"b""c",d');
        $sep = new VMVariable();
        $sep->string(',');
        $enc = new VMVariable();
        $enc->string('"');
        $esc = new VMVariable();
        $esc->string('"');
        $frame->calledArgs = [$arg, $sep, $enc, $esc];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b"c', 'd'], $vals);
    }

    public function testEnumCaseOperandTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $enum = new ClassEntry('E');
        $enum->isEnum = true;
        $enum->backedType = 'string';
        $backing = new VMVariable();
        $backing->string('a,b');
        $case = EnumCaseSupport::createCase($enum, 'A', $backing);

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$case];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('str_getcsv(): Argument #1 ($string) must be of type string, E given');
        $fn->execute($frame);
    }

    public function testNullCoercesWithoutStrict(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $arg = new VMVariable();
        $arg->null();

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $this->assertSame(1, $ht->getNumElements());
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->type;
        }
        $this->assertSame([VMVariable::TYPE_NULL], $vals);
    }

    public function testNullSeparatorUsesDefault(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $input = new VMVariable();
        $input->string('a,b');
        $separator = new VMVariable();
        $separator->null();

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$input, $separator];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b'], $vals);
    }

    public function testNullEnclosureUsesDefault(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $input = new VMVariable();
        $input->string('a,b');
        $sep = new VMVariable();
        $sep->string(',');
        $enclosure = new VMVariable();
        $enclosure->null();

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$input, $sep, $enclosure];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b'], $vals);
    }

    public function testNullEscapeUsesDefault(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $input = new VMVariable();
        $input->string('a,b');
        $sep = new VMVariable();
        $sep->string(',');
        $enc = new VMVariable();
        $enc->string('"');
        $escape = new VMVariable();
        $escape->null();

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$input, $sep, $enc, $escape];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b'], $vals);
    }

    /** @dataProvider newlineOnlyProvider */
    public function testNewlineOnlyInputYieldsNullField(string $input): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($input);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->toArray();
        $this->assertSame(1, $ht->getNumElements());
        $vals = [];
        foreach ($ht->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->type;
        }
        $this->assertSame([VMVariable::TYPE_NULL], $vals);
    }

    /** @return list<array{string}> */
    public static function newlineOnlyProvider(): array
    {
        return [
            ["\n"],
            ["\r\n"],
            ["\r"],
        ];
    }

    /** Issue #18592 — lone quote / unterminated empty enclosure yields NUL byte (php-src file.c PHP 8.2). */
    public function testLoneQuoteYieldsNulByteField(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('"');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $vals = [];
        foreach ($frame->returnVar->toArray()->iterate(true) as $v) {
            $vals[] = $v->resolveIndirect()->toString();
        }
        $this->assertSame(["\0"], $vals);
    }

    public function testClosedEmptyQuoteYieldsEmptyString(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('""');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $field = $frame->returnVar->toArray()->iterate(true)[0]->resolveIndirect()->toString();
        $this->assertSame(0, \strlen($field));
    }
}
