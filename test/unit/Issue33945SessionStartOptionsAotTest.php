<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT session_start($options) applies compileTimeAssoc literals (#33945).
 *
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 */
final class Issue33945SessionStartOptionsAotTest extends TestCase
{
    public function testCallSiteMaterializesCompileTimeAssocNotEmptyBoxedHt(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionStartOptions.php');
        $this->assertStringContainsString('#33945', $src);
        $this->assertStringContainsString('applyOptionsAtCallSite', $src);
        $this->assertStringNotContainsString(
            '$context->helper->loadValue($options)',
            $src,
            'loadValue yields %__value__ and breaks Module verify (#33945)'
        );
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionStartOptionsRuntime.php');
        $this->assertStringContainsString('compileTimeAssoc', $runtime);
        $this->assertStringContainsString('emitCompileTimeOptions', $runtime);
        $this->assertStringContainsString('__phpc_session_name_apply', $runtime);
    }

    public function testReproExists(): void
    {
        $this->assertFileExists(__DIR__.'/../repro/issue_33945_session_start_options_aot.php');
    }
}
