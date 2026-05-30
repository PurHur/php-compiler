<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** VM/JIT/AOT compliance for get_declared_enums() (issue #3538). */
final class GetDeclaredEnumsBuiltinTest extends TestCase
{
    public function testCompliancePhptRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'get_declared_enums.phpt',
            'get_declared_enums_jit.phpt',
        ] as $file) {
            $this->assertFileExists($root . '/test/compliance/cases/stdlib/' . $file);
        }
        $this->assertFileExists($root . '/test/fixtures/aot/cases/get_declared_enums.phpt');
    }
}
