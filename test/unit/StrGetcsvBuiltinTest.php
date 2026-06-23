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

    public function testNullOperandTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new str_getcsv();
        $arg = new VMVariable();
        $arg->null();

        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('str_getcsv(): Argument #1 ($string) must be of type string, null given');
        $fn->execute($frame);
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
}
