<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\md5;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #179: md5() VM builtin. */
final class Md5BuiltinTest extends TestCase
{
    public function testHexDigest(): void
    {
        $runtime = new Runtime();
        $data = new VMVariable();
        $data->string('abc');
        $builtin = new md5();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$data];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $out = $callFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $out->type);
        $this->assertSame('900150983cd24fb0d6963f7d28e17f72', $out->toString());
    }

    public function testRawDigest(): void
    {
        $runtime = new Runtime();
        $data = new VMVariable();
        $data->string('abc');
        $raw = new VMVariable();
        $raw->bool(true);
        $builtin = new md5();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$data, $raw];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $out = $callFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $out->type);
        $this->assertSame(
            '900150983cd24fb0d6963f7d28e17f72',
            bin2hex($out->toString())
        );
    }
}
