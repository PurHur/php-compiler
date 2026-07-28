<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPUnit\Framework\TestCase;

/** Thin AOT array_reduce Closure decline message (#24117). */
final class ArrayReduceThinAotClosureDeclineTest extends TestCase
{
    public function testThinAotClosureRejectionMessageIsExplicit(): void
    {
        $msg = ArrayReduceCallbackPolicy::thinAotClosureRejectionMessage();
        $this->assertStringContainsString('array_reduce() with a Closure callback', $msg);
        $this->assertStringContainsString('thin standalone AOT', $msg);
        $this->assertStringNotContainsString('undefined method', strtolower($msg));
        $this->assertStringNotContainsString('::null()', strtolower($msg));
    }
}
