<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** VM/JIT compliance: get_declared_enums() must not exist (#11248). */
final class GetDeclaredEnumsBuiltinTest extends TestCase
{
    public function testCompliancePhptRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'get_declared_enums_absent.phpt',
            'get_declared_enums_absent_jit.phpt',
            'get_declared_enums_call_fatal.phpt',
        ] as $file) {
            $this->assertFileExists($root . '/test/compliance/cases/stdlib/' . $file);
        }
        $this->assertFileExists($root . '/test/repro-maintainer/parity_get_declared_enums_phantom.php');
    }
}
