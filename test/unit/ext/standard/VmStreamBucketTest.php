<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\ext\standard;

use PHPCompiler\ext\standard\VmStreamBucket;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\ext\standard\VmStreamBucket */
final class VmStreamBucketTest extends TestCase
{
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->runtime = new Runtime();
    }

    public function testBrigadeAppendMakeWriteableRoundtrip(): void
    {
        $brigadeId = VmStreamBucket::allocateBrigade();
        $brigade = new Variable();
        VmStreamBucket::brigadeHandle($brigade, $brigadeId);

        $bucket = VmStreamBucket::newBucketObject($this->runtime->vmContext, 0, 'payload');
        VmStreamBucket::append($brigadeId, $bucket->toObject());

        $out = VmStreamBucket::makeWriteable($this->runtime->vmContext, $brigadeId);
        self::assertInstanceOf(Variable::class, $out);
        self::assertSame('payload', $out->toObject()->getProperty('data')->toString());
        self::assertNull(VmStreamBucket::makeWriteable($this->runtime->vmContext, $brigadeId));
    }

    public function testPrependOrdersBeforeAppend(): void
    {
        $brigadeId = VmStreamBucket::allocateBrigade();
        $first = VmStreamBucket::newBucketObject($this->runtime->vmContext, 0, 'first');
        $second = VmStreamBucket::newBucketObject($this->runtime->vmContext, 0, 'second');
        VmStreamBucket::append($brigadeId, $second->toObject());
        VmStreamBucket::prepend($brigadeId, $first->toObject());

        $out = VmStreamBucket::makeWriteable($this->runtime->vmContext, $brigadeId);
        self::assertSame('first', $out->toObject()->getProperty('data')->toString());
    }

    public function testNewBucketObjectIsStdClass(): void
    {
        $bucket = VmStreamBucket::newBucketObject($this->runtime->vmContext, 0, 'payload');
        self::assertSame('stdClass', $bucket->toObject()->class->name);
    }
}
