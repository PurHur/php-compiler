<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\yaml\YamlConstants;
use PHPUnit\Framework\TestCase;

/** YAML_* PECL constants (#27873). */
final class YamlConstantsSurfaceTest extends TestCase
{
    public function testPeclLongConstantValues(): void
    {
        self::assertSame(0, YamlConstants::ANY_ENCODING);
        self::assertSame(1, YamlConstants::UTF8_ENCODING);
        self::assertSame(2, YamlConstants::LN_BREAK);
        self::assertSame(3, YamlConstants::DOUBLE_QUOTED_SCALAR_STYLE);
        self::assertSame('!php/object', YamlConstants::PHP_TAG);
        self::assertSame(1, YamlConstants::registeredConstants()['YAML_UTF8_ENCODING']);
    }

    public function testConstantsRegisteredUnderForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue($ctx->isUserConstantDefined('YAML_UTF8_ENCODING'));
            self::assertTrue($ctx->isUserConstantDefined('YAML_LN_BREAK'));
            self::assertTrue($ctx->isUserConstantDefined('YAML_PHP_TAG'));
            self::assertSame(1, $ctx->constants['YAML_UTF8_ENCODING']->toInt());
            self::assertSame('!php/object', $ctx->constants['YAML_PHP_TAG']->toString());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
