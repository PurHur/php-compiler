<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\uniqid;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for uniqid() (#2219). */
final class UniqidBuiltinTest extends TestCase
{
    public function testDefaultAndPrefix(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $id = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame(13, strlen($id));

        $frame2 = $fn->getFrame($runtime->vmContext);
        $pfx = new VMVariable();
        $pfx->string('pfx_');
        $frame2->calledArgs = [$pfx];
        $frame2->returnVar = new VMVariable();
        $fn->execute($frame2);
        $this->assertStringStartsWith('pfx_', $frame2->returnVar->resolveIndirect()->toString());
    }

    public function testIntPrefixCoercion(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $pfx = new VMVariable();
        $pfx->int(42);
        $frame->calledArgs = [$pfx];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertStringStartsWith('42', $frame->returnVar->resolveIndirect()->toString());
    }

    public function testArrayPrefixTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $bad = new VMVariable();
        $bad->array(new HashTable());
        $frame->calledArgs = [$bad];
        $frame->returnVar = new VMVariable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('uniqid(): Argument #1 ($prefix) must be of type string, array given');
        $fn->execute($frame);
    }

    public function testMoreEntropyLength(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $empty = new VMVariable();
        $empty->string('');
        $entropy = new VMVariable();
        $entropy->bool(true);
        $frame->calledArgs = [$empty, $entropy];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $id = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame(23, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{13}[0-9]\\.[0-9]{8}$/', $id);
    }

    public function testMoreEntropyIntCoercion(): void
    {
        $runtime = new Runtime();
        $fn = new uniqid();
        $frame = $fn->getFrame($runtime->vmContext);
        $empty = new VMVariable();
        $empty->string('');
        $entropy = new VMVariable();
        $entropy->int(1);
        $frame->calledArgs = [$empty, $entropy];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $id = $frame->returnVar->resolveIndirect()->toString();
        $this->assertSame(23, strlen($id));
    }
}
