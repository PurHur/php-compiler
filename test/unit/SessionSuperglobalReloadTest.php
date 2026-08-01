<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * $_SESSION must re-load sg_SESSION after session_start() replaces the HT (#26411).
 */
final class SessionSuperglobalReloadTest extends TestCase
{
    public function testSessionSuperglobalUsesKindVariableNotSnapshot(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/SuperglobalInit.php'
        );
        $this->assertStringContainsString('RUNTIME_SESSION_SUPERGLOBALS', $source);
        $this->assertStringContainsString('#26411', $source);
        $this->assertStringContainsString('Variable::KIND_VARIABLE', $source);
        $this->assertStringContainsString('phpc_session_save_to_disk', $source);
    }

    public function testVariableFreeSkipsSuperglobalOwnedHashtable(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Variable.php'
        );
        $this->assertStringContainsString(
            'KIND_VARIABLE $_SESSION re-loads sg_*',
            $source
        );
        $this->assertStringContainsString('null !== $this->superglobalName', $source);
    }
}
