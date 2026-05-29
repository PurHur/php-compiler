<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\json_validate;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for json_validate() (#3101). */
final class JsonValidateBuiltinTest extends TestCase
{
    public function testValidObject(): void
    {
        $this->assertTrue($this->runValidate('{"a":1}'));
    }

    public function testInvalidSyntax(): void
    {
        $this->assertFalse($this->runValidate('{'));
    }

    public function testDepthZeroThrows(): void
    {
        $runtime = new Runtime();
        $fn = new json_validate();
        $frame = $fn->getFrame($runtime->vmContext);
        $jsonVar = new VMVariable();
        $jsonVar->string('[]');
        $depthVar = new VMVariable();
        $depthVar->int(0);
        $frame->calledArgs = [$jsonVar, $depthVar];
        $frame->returnVar = new VMVariable();
        $this->expectException(\ValueError::class);
        $fn->execute($frame);
    }

    private function runValidate(string $json, int $depth = 512): bool
    {
        $runtime = new Runtime();
        $fn = new json_validate();
        $frame = $fn->getFrame($runtime->vmContext);
        $jsonVar = new VMVariable();
        $jsonVar->string($json);
        $frame->calledArgs = [$jsonVar];
        if (512 !== $depth) {
            $depthVar = new VMVariable();
            $depthVar->int($depth);
            $frame->calledArgs[] = $depthVar;
        }
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect()->toBool();
    }
}
