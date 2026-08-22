<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * setcookie/setrawcookie Reflection: array|int $expires_or_options (#25380).
 *
 * @see php-src ext/standard/head.stub.php
 */
final class SetcookieExpiresOrOptionsReflection25380Test extends TestCase
{
    public function testReflectionExpiresOrOptionsIsArrayIntViaVm(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25380_setcookie_expires_or_options_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25380_setcookie_expires_or_options_reflection.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "setcookie|array|int\n"
            ."setrawcookie|array|int\n"
            ."options_array_call=ok\n",
            (string) ob_get_clean()
        );
    }
}
