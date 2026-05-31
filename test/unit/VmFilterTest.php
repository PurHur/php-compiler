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

    public function testValidateIntRejectsLeadingZero(): void
    {
        $v = new Variable();
        $v->string('0123');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testValidateIntAcceptsPlainDecimal(): void
    {
        $v = new Variable();
        $v->string('123');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(123, $out->toInt());
    }

    public function testValidateIntAcceptsLoneZero(): void
    {
        $v = new Variable();
        $v->string('0');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(0, $out->toInt());
    }

    public function testIsIntegerStringRejectsLeadingZeros(): void
    {
        $this->assertFalse(VmFilter::isIntegerString('0123'));
        $this->assertFalse(VmFilter::isIntegerString('00'));
        $this->assertFalse(VmFilter::isIntegerString('-0123'));
        $this->assertTrue(VmFilter::isIntegerString('0'));
        $this->assertTrue(VmFilter::isIntegerString('-0'));
        $this->assertTrue(VmFilter::isIntegerString('123'));
        $this->assertTrue(VmFilter::isIntegerString('-42'));
    }
}
