<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT session_start($options) must pass __value__* to the apply ABI (#33945).
 *
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 */
final class Issue33945SessionStartOptionsAotTest extends TestCase
{
    public function testCallSitePassesValuePointerNotLoadedStruct(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionStartOptions.php');
        $this->assertStringContainsString('#33945', $src);
        $this->assertStringContainsString('JitValueBox::valuePtrFromVariable', $src);
        $this->assertStringNotContainsString(
            '$context->helper->loadValue($options)',
            $src,
            'loadValue yields %__value__ and breaks Module verify (#33945)'
        );
    }

    public function testReproExists(): void
    {
        $this->assertFileExists(__DIR__.'/../repro/issue_33945_session_start_options_aot.php');
    }
}
