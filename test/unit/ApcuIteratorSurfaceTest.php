<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\apcu\ApcuConstants;
use PHPCompiler\ext\apcu\VmApcu;
use PHPCompiler\ext\apcu\VmApcuIterator;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** APCUIterator + APC_ITER_* registration (#27877). */
final class ApcuIteratorSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        VmApcu::reset();
    }

    protected function tearDown(): void
    {
        VmApcu::reset();
        $prev = getenv('PHP_COMPILER_PROFILE');
        if (false === $prev) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
            $_ENV['PHP_COMPILER_PROFILE'] = $prev;
        }
    }

    public function testConstantsMatchPecl(): void
    {
        self::assertSame(0x1, ApcuConstants::LIST_ACTIVE);
        self::assertSame(1 << 1, ApcuConstants::ITER_KEY);
        self::assertSame(0xffffffff, ApcuConstants::ITER_ALL);
        self::assertArrayHasKey('APC_ITER_KEY', ApcuConstants::registeredConstants());
        self::assertSame(2, ApcuConstants::registeredConstants()['APC_ITER_KEY']);
    }

    public function testIteratorListsStoredKeysUnderForwardProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        self::assertTrue($ctx->isUserConstantDefined('APC_ITER_KEY'));
        self::assertTrue($ctx->isUserConstantDefined('APC_LIST_ACTIVE'));
        self::assertArrayHasKey(VmApcuIterator::CLASS_LC, $ctx->classes);

        $v1 = new Variable();
        $v1->int(1);
        $v2 = new Variable();
        $v2->int(2);
        VmApcu::store('alpha', $v1);
        VmApcu::store('beta', $v2);

        $object = new ObjectEntry($ctx->classes[VmApcuIterator::CLASS_LC]);
        VmApcuIterator::construct($object, null, ApcuConstants::ITER_KEY, ApcuConstants::LIST_ACTIVE);
        self::assertTrue(VmApcuIterator::valid($object));
        self::assertSame('alpha', VmApcuIterator::key($object));
        $cur = VmApcuIterator::current($object);
        self::assertSame(Variable::TYPE_ARRAY, $cur->type);
        VmApcuIterator::next($object);
        self::assertSame('beta', VmApcuIterator::key($object));
        VmApcuIterator::next($object);
        self::assertFalse(VmApcuIterator::valid($object));
        self::assertSame(2, VmApcuIterator::getTotalCount($object));
    }
}
