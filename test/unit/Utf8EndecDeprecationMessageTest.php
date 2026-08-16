<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Utf8EndecDeprecation;
use PHPUnit\Framework\TestCase;

/**
 * utf8_encode()/utf8_decode() E_DEPRECATED text matches Zend profile (#29249, #31176).
 */
final class Utf8EndecDeprecationMessageTest extends TestCase
{
    public function testMessageMatchesZend82ShortWordingOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertSame(
                'Function utf8_encode() is deprecated',
                Utf8EndecDeprecation::message('utf8_encode')
            );
            $this->assertSame(
                'Function utf8_decode() is deprecated',
                Utf8EndecDeprecation::message('utf8_decode')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMessageMatchesZend84SincePhpNetWordingWhenProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $suffix = ' is deprecated since 8.2, visit the php.net documentation for various alternatives';
            $this->assertSame(
                'Function utf8_encode()'.$suffix,
                Utf8EndecDeprecation::message('utf8_encode')
            );
            $this->assertSame(
                'Function utf8_decode()'.$suffix,
                Utf8EndecDeprecation::message('utf8_decode')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
