<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\sha1;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #2160: sha1() VM builtin. */
final class Sha1BuiltinTest extends TestCase
{
    public function testHexDigest(): void
    {
        $runtime = new Runtime();
        $data = new VMVariable();
        $data->string('abc');
        $builtin = new sha1();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$data];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $out = $callFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $out->type);
        $this->assertSame('a9993e364706816aba3e25717850c26c9cd0d89d', $out->toString());
    }

    public function testRawDigest(): void
    {
        $runtime = new Runtime();
        $data = new VMVariable();
        $data->string('abc');
        $raw = new VMVariable();
        $raw->bool(true);
        $builtin = new sha1();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$data, $raw];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $out = $callFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $out->type);
        $this->assertSame(
            'a9993e364706816aba3e25717850c26c9cd0d89d',
            bin2hex($out->toString())
        );
    }
}
