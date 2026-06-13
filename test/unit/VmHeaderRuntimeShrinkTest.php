<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM header() must not delegate to host \\header() (#8274, phase 2 of #5344). */
final class VmHeaderRuntimeShrinkTest extends TestCase
{
    public function testHeaderBuiltinDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/header_.php');
        $this->assertStringNotContainsString('\\header(', $source);
        $this->assertStringContainsString('ResponseContext::addHeader', $source);
    }
}
