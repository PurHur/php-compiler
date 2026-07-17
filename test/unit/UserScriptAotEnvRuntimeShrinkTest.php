<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\UserScriptAotEnv;
use PHPUnit\Framework\TestCase;

/**
 * UserScriptAotEnv SSOT for PHP_COMPILER_AOT_USER_SCRIPT (#20246, #20256).
 */
final class UserScriptAotEnvRuntimeShrinkTest extends TestCase
{
    public function testUserScriptAotEnvIsSsotForEnvFlag(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        try {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            $this->assertFalse(UserScriptAotEnv::isActive());
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
            $this->assertTrue(UserScriptAotEnv::isActive());
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=true');
            $this->assertTrue(UserScriptAotEnv::isActive());
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
            }
        }
    }

    public function testCallSitesUseUserScriptAotEnvNotRawGetenv(): void
    {
        $dom = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString('UserScriptAotEnv::isActive', $dom);
        $this->assertStringNotContainsString("getenv('PHP_COMPILER_AOT_USER_SCRIPT')", $dom);

        $rand = (string) file_get_contents(__DIR__.'/../../lib/JIT/RandomizerInstanceMethodJit.php');
        $this->assertStringContainsString('UserScriptAotEnv::isActive', $rand);
        $this->assertStringNotContainsString("getenv('PHP_COMPILER_AOT_USER_SCRIPT')", $rand);

        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('UserScriptAotEnv::isActive', $context);
        $this->assertStringContainsString('public function isUserScriptAot()', $context);

        $stream = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('UserScriptAotEnv::isActive', $stream);

        $xml = (string) file_get_contents(__DIR__.'/../../ext/xmlwriter/JitXmlWriterUserScript.php');
        $this->assertStringContainsString('UserScriptAotEnv::isActive', $xml);
    }

    public function testSpineRequiresUserScriptAotEnvBeforeContext(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $envPos = strpos($spine, 'UserScriptAotEnv.php');
        $ctxPos = strpos($spine, 'lib/JIT/Context.php');
        $this->assertNotFalse($envPos);
        $this->assertNotFalse($ctxPos);
        $this->assertLessThan($ctxPos, $envPos);
    }
}
