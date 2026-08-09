<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Utf8EndecDeprecation;
use PHPUnit\Framework\TestCase;

/**
 * utf8_encode()/utf8_decode() E_DEPRECATED text matches Zend (#29249, php-src ext/standard/utf8.c).
 */
final class Utf8EndecDeprecationMessageTest extends TestCase
{
    public function testMessageMatchesZendSince82PhpNetHint(): void
    {
        $suffix = ' is deprecated since 8.2, visit the php.net documentation for various alternatives';
        $this->assertSame(
            'Function utf8_encode()'.$suffix,
            Utf8EndecDeprecation::message('utf8_encode')
        );
        $this->assertSame(
            'Function utf8_decode()'.$suffix,
            Utf8EndecDeprecation::message('utf8_decode')
        );
    }
}
