<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\json_validate;
use PHPCompiler\ext\standard\VmJsonFlags;
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

    public function testValidEmptyArray(): void
    {
        $this->assertTrue($this->runValidate('[]'));
    }

    public function testDepthExceededReturnsFalse(): void
    {
        // php-src json_validate: over-deep → false + JSON_ERROR_DEPTH (#23007), not ValueError.
        $this->assertFalse($this->runValidate('{"a":1}', 1));
        $this->assertSame('Maximum stack depth exceeded', \PHPCompiler\ext\standard\VmJson::lastErrorMsg());
        $this->assertFalse($this->runValidate('{"a":{"b":1}}', 2));
        $this->assertSame('Maximum stack depth exceeded', \PHPCompiler\ext\standard\VmJson::lastErrorMsg());
        $this->assertTrue($this->runValidate('{"a":{"b":1}}', 3));
        $this->assertSame('No error', \PHPCompiler\ext\standard\VmJson::lastErrorMsg());
    }

    public function testInvalidUtf8WithoutFlag(): void
    {
        $bad = '{"x":"' . "\xC3\x28" . '"}';
        $this->assertFalse($this->runValidate($bad, 512, 0));
    }

    public function testInvalidUtf8WithIgnoreFlag(): void
    {
        $bad = '{"x":"' . "\xC3\x28" . '"}';
        $this->assertTrue($this->runValidate($bad, 512, VmJsonFlags::INVALID_UTF8_IGNORE));
    }

    public function testValidJsonWithIgnoreFlag(): void
    {
        $this->assertTrue($this->runValidate('{"a":1}', 512, VmJsonFlags::INVALID_UTF8_IGNORE));
    }

    public function testInvalidFlagsThrow(): void
    {
        $runtime = new Runtime();
        $fn = new json_validate();
        $frame = $fn->getFrame($runtime->vmContext);
        $jsonVar = new VMVariable();
        $jsonVar->string('[]');
        $depthVar = new VMVariable();
        $depthVar->int(512);
        $flagsVar = new VMVariable();
        $flagsVar->int(VmJsonFlags::INVALID_UTF8_IGNORE | 1);
        $frame->calledArgs = [$jsonVar, $depthVar, $flagsVar];
        $frame->returnVar = new VMVariable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Argument #3 ($flags)');
        $fn->execute($frame);
    }

    public function testNullJsonThrowsTypeErrorOnForwardProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $fn = new json_validate();
            $frame = $fn->getFrame($runtime->vmContext);
            $jsonVar = new VMVariable();
            $jsonVar->null();
            $frame->calledArgs = [$jsonVar];
            $frame->returnVar = new VMVariable();
            $this->expectException(\TypeError::class);
            $fn->execute($frame);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    private function runValidate(string $json, int $depth = 512, int $flags = 0): bool
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
        if (0 !== $flags) {
            if (512 === $depth) {
                $depthVar = new VMVariable();
                $depthVar->int($depth);
                $frame->calledArgs[] = $depthVar;
            }
            $flagsVar = new VMVariable();
            $flagsVar->int($flags);
            $frame->calledArgs[] = $flagsVar;
        }
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect()->toBool();
    }
}
