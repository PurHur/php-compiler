<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmObjectDebugType;
use PHPUnit\Framework\TestCase;

/** Anonymous class public labels — Prefix@anonymous (#28840 / #17443). */
final class VmObjectDebugTypeAnonymousNameTest extends TestCase
{
    public function testStripsNulProvenanceKeepingPrefix(): void
    {
        $this->assertSame(
            'Countable@anonymous',
            VmObjectDebugType::fromClassName("Countable@anonymous\0/tmp/x.php:2\$0")
        );
        $this->assertSame(
            'Base@anonymous',
            VmObjectDebugType::fromClassName("Base@anonymous\0/tmp/x.php:3\$1")
        );
        $this->assertSame(
            'class@anonymous',
            VmObjectDebugType::fromClassName("class@anonymous\0/tmp/x.php:4\$2")
        );
    }

    public function testAlreadyPublicAnonNameUnchanged(): void
    {
        $this->assertSame('A@anonymous', VmObjectDebugType::fromClassName('A@anonymous'));
        $this->assertSame('Foo', VmObjectDebugType::fromClassName('Foo'));
    }
}
