<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

final class UndefinedArrayKeyTest extends TestCase
{
    public function testHashTableKeyExists(): void
    {
        $ht = new HashTable();
        $key = new Variable();
        $key->string('present');
        $this->assertFalse($ht->keyExists($key));

        $val = new Variable();
        $val->string('x');
        $ht->add('present', $val);
        $this->assertTrue($ht->keyExists($key));

        $missing = new Variable();
        $missing->string('absent');
        $this->assertFalse($ht->keyExists($missing));
    }

    public function testErrorReporterFormatsArrayKeysLikePhp8(): void
    {
        $reporter = new ErrorReporter();
        $ref = new \ReflectionClass(ErrorReporter::class);
        $method = $ref->getMethod('formatArrayKey');
        $method->setAccessible(true);

        $stringKey = new Variable();
        $stringKey->string('name');
        $this->assertSame('"name"', $method->invoke($reporter, $stringKey));

        $intKey = new Variable();
        $intKey->int(0);
        $this->assertSame('0', $method->invoke($reporter, $intKey));
    }
}
