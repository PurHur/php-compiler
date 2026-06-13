<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM setcookie/setrawcookie must not delegate to host Zend (#5344 phase 3, pairs #8274 header). */
final class VmSetcookieRuntimeShrinkTest extends TestCase
{
    public function testSetcookieBuiltinDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/setcookie.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\setcookie\\s*\\(/', $source);
        $this->assertStringContainsString('ResponseContext::addHeader', $source);
        $this->assertStringContainsString('SetcookieLine::build', $source);
    }

    public function testSetrawcookieBuiltinDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/setrawcookie.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\setrawcookie\\s*\\(/', $source);
        $this->assertStringContainsString('ResponseContext::addHeader', $source);
        $this->assertStringContainsString('SetcookieLine::build', $source);
    }
}
