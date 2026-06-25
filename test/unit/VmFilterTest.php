<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\filter\VmFilter;
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

    public function testValidateIntAllowHexFlag(): void
    {
        $v = new Variable();
        $v->string('0x10');
        $flags = new Variable();
        $flags->int(VmFilter::FILTER_FLAG_ALLOW_HEX);
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT, $flags);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(16, $out->toInt());
    }

    public function testValidateIntAllowOctalFlag(): void
    {
        $v = new Variable();
        $v->string('010');
        $flags = new Variable();
        $flags->int(VmFilter::FILTER_FLAG_ALLOW_OCTAL);
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT, $flags);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(8, $out->toInt());
    }

    public function testValidateIntHexWithoutFlagReturnsFalse(): void
    {
        $v = new Variable();
        $v->string('0x10');
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

    public function testNullOnFailureReturnsNullForInvalidInt(): void
    {
        $v = new Variable();
        $v->string('not-int');
        $flag = new Variable();
        $flag->int(VmFilter::FILTER_NULL_ON_FAILURE);
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT, $flag);
        $this->assertSame(Variable::TYPE_NULL, $out->type);
    }

    public function testNullOnFailureStillReturnsFalseWithoutFlag(): void
    {
        $v = new Variable();
        $v->string('not-int');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_INT);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testNullOnFailureReturnsNullForInvalidEmail(): void
    {
        $v = new Variable();
        $v->string('not-an-email');
        $flag = new Variable();
        $flag->int(VmFilter::FILTER_NULL_ON_FAILURE);
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_EMAIL, $flag);
        $this->assertSame(Variable::TYPE_NULL, $out->type);
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

    public function testIsValidEmailSubsetAcceptsPracticalAddresses(): void
    {
        $this->assertTrue(VmFilter::isValidEmailSubset('user@example.com'));
        $this->assertTrue(VmFilter::isValidEmailSubset('a@b.co'));
    }

    public function testIsValidEmailSubsetRejectsInvalidAddresses(): void
    {
        $this->assertFalse(VmFilter::isValidEmailSubset(''));
        $this->assertFalse(VmFilter::isValidEmailSubset('not-an-email'));
        $this->assertFalse(VmFilter::isValidEmailSubset('bad@'));
        $this->assertFalse(VmFilter::isValidEmailSubset('@example.com'));
        $this->assertFalse(VmFilter::isValidEmailSubset('a@b'));
        $this->assertFalse(VmFilter::isValidEmailSubset('a@@b.co'));
    }

    public function testUnknownFilterReturnsFalse(): void
    {
        $v = new Variable();
        $v->string('x');
        $out = VmFilter::filterVar($v, 99999);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testUnknownFilterWarningMessage(): void
    {
        $this->assertSame(
            'filter_var(): Unknown filter with ID 99999',
            VmFilter::unknownFilterWarningMessage(99999)
        );
    }

    public function testValidateRegexpMatch(): void
    {
        $value = new Variable();
        $value->string('abc123');
        $options = new Variable();
        $options->array(self::regexpOptions('/^[a-z0-9]+$/'));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_REGEXP, $options);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('abc123', $out->toString());
    }

    public function testValidateRegexpNoMatchReturnsFalse(): void
    {
        $value = new Variable();
        $value->string('!!!');
        $options = new Variable();
        $options->array(self::regexpOptions('/^[a-z0-9]+$/'));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_REGEXP, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testValidateRegexpMissingOptionThrowsValueError(): void
    {
        $value = new Variable();
        $value->string('abc');
        $options = new Variable();
        $options->array(new \PHPCompiler\VM\HashTable());
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('filter_var(): "regexp" option is missing');
        VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_REGEXP, $options);
    }

    public function testValidateRegexpInvalidPatternReturnsFalse(): void
    {
        $value = new Variable();
        $value->string('abc');
        $options = new Variable();
        $options->array(self::regexpOptions('/[/'));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_REGEXP, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testValidateUrl(): void
    {
        $v = new Variable();
        $v->string('https://example.com');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_URL);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('https://example.com', $out->toString());
    }

    public function testValidateUrlRejectsInvalid(): void
    {
        $v = new Variable();
        $v->string('not a url');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_URL);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testIsValidUrlSubset(): void
    {
        $this->assertTrue(VmFilter::isValidUrlSubset('https://example.com'));
        $this->assertTrue(VmFilter::isValidUrlSubset('http://127.0.0.1:8080/path?q=1#frag'));
        $this->assertTrue(VmFilter::isValidUrlSubset('ftp://example.com'));
        $this->assertFalse(VmFilter::isValidUrlSubset('not a url'));
        $this->assertFalse(VmFilter::isValidUrlSubset('http://'));
    }

    /** @return \PHPCompiler\VM\HashTable */
    private static function regexpOptions(string $pattern): \PHPCompiler\VM\HashTable
    {
        $outer = new \PHPCompiler\VM\HashTable();
        $inner = new \PHPCompiler\VM\HashTable();
        $regexp = new Variable();
        $regexp->string($pattern);
        $inner->add('regexp', $regexp);
        $options = new Variable();
        $options->array($inner);
        $outer->add('options', $options);

        return $outer;
    }
}
