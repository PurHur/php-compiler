<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\InstanceOfClassName;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Dynamic `new $object` classname operand (#30058). */
final class NewObjectClassname30058Test extends TestCase
{
    public function testResolveClassNameFromObjectPreservesClassEntry(): void
    {
        $class = new ClassEntry('UserNewObj');
        $obj = new ObjectEntry($class);
        $var = new Variable();
        $var->object($obj);

        $this->assertSame('UserNewObj', InstanceOfClassName::resolveClassNamePreservingCase($var));
        $this->assertSame('usernewobj', InstanceOfClassName::resolveClassName($var));
    }

    public function testResolveClassNameFromStringPreservesCase(): void
    {
        $var = new Variable();
        $var->string('StdClass');

        $this->assertSame('StdClass', InstanceOfClassName::resolveClassNamePreservingCase($var));
        $this->assertSame('stdclass', InstanceOfClassName::resolveClassName($var));
    }

    public function testResolveClassNameRejectsNonStringNonObject(): void
    {
        $var = new Variable();
        $var->int(1);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage(InstanceOfClassName::ERROR_MESSAGE);
        InstanceOfClassName::resolveClassNamePreservingCase($var);
    }
}
