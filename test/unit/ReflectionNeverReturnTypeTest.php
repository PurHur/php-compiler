<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCfg\Op\Type\Never_;
use PHPUnit\Framework\TestCase;

/** ReflectionNamedType for :never return — getName() never not TypeError (#9655). */
final class ReflectionNeverReturnTypeTest extends TestCase
{
    public function testCfgTypeStringNever(): void
    {
        $this->assertSame('never', ReflectionTypeSupport::cfgTypeString(new Never_()));
    }
}
