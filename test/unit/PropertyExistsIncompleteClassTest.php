<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\ext\standard\PropertyExistsJitHelper;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Runtime;
use PHPCompiler\VM\IncompleteClassSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * property_exists() on __PHP_Incomplete_Class — SSOT used by VM + PropertyExistsJitHelper (AOT) (#26366).
 *
 * Full AOT binary smoke is blocked by UnserializeJitHelper context typing when creating
 * incomplete objects from payloads; this unit exercises the same helper path AOT links.
 */
final class PropertyExistsIncompleteClassTest extends TestCase
{
    public function testVmReflectionAndJitHelperWarnAndReturnFalse(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertArrayHasKey('__php_incomplete_class', $ctx->classes);

        $object = new ObjectEntry($ctx->classes['__php_incomplete_class']);
        $nameSlot = $object->allocateProperty(IncompleteClassSupport::NAME_PROP);
        $nameSlot->string('Secret');
        $dyn = $object->allocateProperty('v');
        $dyn->int(1);

        $this->assertTrue(IncompleteClassSupport::isIncomplete($object));

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        NativeLastError::clear();
        $this->assertFalse(VmReflection::propertyExists($ctx, $var, 'v'));
        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertStringStartsWith(
            'property_exists(): The script tried to access a property on an incomplete object.',
            ErrorLastJitHelper::getMessage()
        );

        NativeLastError::clear();
        Superglobals::setActiveContext($ctx);
        try {
            $this->assertFalse(PropertyExistsJitHelper::existsArgv($var, 'v'));
            $this->assertTrue(ErrorLastJitHelper::isActive());
            $this->assertStringStartsWith(
                'property_exists(): The script tried to access a property on an incomplete object.',
                ErrorLastJitHelper::getMessage()
            );
        } finally {
            Superglobals::setActiveContext(null);
        }
    }
}
