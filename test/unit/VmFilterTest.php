<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFilter;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class VmFilterTest extends TestCase
{
    public function testValidateIntString(): void
    {
        $v = new Variable();
        $v->string('42');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(42, $out->toInt());
    }

    public function testValidateEmail(): void
    {
        $v = new Variable();
        $v->string('user@example.com');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_EMAIL);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('user@example.com', $out->toString());
    }

    public function testInvalidEmailReturnsFalse(): void
    {
        $v = new Variable();
        $v->string('not valid');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_EMAIL);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }
}
