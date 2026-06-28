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

    public function testValidateIpReturnsStringForValidIpv4(): void
    {
        $v = new Variable();
        $v->string('127.0.0.1');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_IP);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('127.0.0.1', $out->toString());
    }

    public function testValidateIpReturnsFalseForInvalidAddress(): void
    {
        $v = new Variable();
        $v->string('not-an-ip');
        $out = VmFilter::filterVar($v, VmFilter::FILTER_VALIDATE_IP);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testIsValidIpAddressAcceptsIpv6(): void
    {
        $this->assertTrue(VmFilter::isValidIpAddress('::1'));
        $this->assertTrue(VmFilter::isValidIpAddress('[2001:db8::1]'));
    }

    public function testIsValidIpAddressRejectsInvalidIpv4(): void
    {
        $this->assertFalse(VmFilter::isValidIpAddress('999.999.999.999'));
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

    public function testValidateIntMinMaxRange(): void
    {
        $value = new Variable();
        $value->string('5');
        $options = new Variable();
        $options->array(self::rangeOptions(10, null));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_INT, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());

        $value->string('10');
        $options->array(self::rangeOptions(10, 20));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_INT, $options);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(10, $out->toInt());
    }

    public function testValidateFloatMinMaxRange(): void
    {
        $value = new Variable();
        $value->string('1.5');
        $options = new Variable();
        $options->array(self::rangeOptions(2.0, null));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_FLOAT, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testValidateEmailUnicodeFlag(): void
    {
        $value = new Variable();
        $value->string('tëst@example.com');
        $options = new Variable();
        $options->array(self::flagsOptions(VmFilter::FILTER_FLAG_EMAIL_UNICODE));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_EMAIL, $options);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('tëst@example.com', $out->toString());

        $outAscii = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_EMAIL);
        $this->assertSame(Variable::TYPE_BOOLEAN, $outAscii->type);
        $this->assertFalse($outAscii->toBool());
    }

    public function testValidateUrlPathRequiredFlag(): void
    {
        $value = new Variable();
        $value->string('http://example.com');
        $options = new Variable();
        $options->array(self::flagsOptions(VmFilter::FILTER_FLAG_PATH_REQUIRED));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_URL, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());
    }

    public function testValidateIpNoPrivRangeFlag(): void
    {
        $value = new Variable();
        $value->string('10.0.0.1');
        $options = new Variable();
        $options->array(self::flagsOptions(VmFilter::FILTER_FLAG_NO_PRIV_RANGE));
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_IP, $options);
        $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
        $this->assertFalse($out->toBool());

        $value->string('8.8.8.8');
        $out = VmFilter::filterVar($value, VmFilter::FILTER_VALIDATE_IP, $options);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('8.8.8.8', $out->toString());
    }

    /** @return \PHPCompiler\VM\HashTable */
    private static function rangeOptions(int|float|null $min, int|float|null $max): \PHPCompiler\VM\HashTable
    {
        $outer = new \PHPCompiler\VM\HashTable();
        $inner = new \PHPCompiler\VM\HashTable();
        if (null !== $min) {
            $minVar = new Variable();
            if (\is_int($min)) {
                $minVar->int($min);
            } else {
                $minVar->float($min);
            }
            $inner->add('min_range', $minVar);
        }
        if (null !== $max) {
            $maxVar = new Variable();
            if (\is_int($max)) {
                $maxVar->int($max);
            } else {
                $maxVar->float($max);
            }
            $inner->add('max_range', $maxVar);
        }
        $options = new Variable();
        $options->array($inner);
        $outer->add('options', $options);

        return $outer;
    }

    /** @return \PHPCompiler\VM\HashTable */
    private static function flagsOptions(int $flags): \PHPCompiler\VM\HashTable
    {
        $outer = new \PHPCompiler\VM\HashTable();
        $flagsVar = new Variable();
        $flagsVar->int($flags);
        $outer->add('flags', $flagsVar);

        return $outer;
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
