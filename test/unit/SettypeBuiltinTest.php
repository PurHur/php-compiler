<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmSettype;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #3112: settype() VM builtin. */
final class SettypeBuiltinTest extends TestCase
{
    public function testInvalidTypeThrowsValueError(): void
    {
        $var = new VMVariable();
        $var->int(1);

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('settype(): Argument #2 ($type) must be a valid type');
        VmSettype::apply($var, 'not-a-type');
    }
}
