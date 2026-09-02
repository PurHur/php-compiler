<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT string assignment must addref (Zend zend_string_copy), not memcpy via __string__separate (#36192).
 *
 * @group aot-lint
 */
final class StringCopyOnWriteAotTest extends TestCase
{
    public function testValueBoxCopyUsesAddrefNotSeparate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmValueCopy.php');
        $this->assertStringContainsString('writeStringToValuePtrByAddref', $src);
        $this->assertStringContainsString('$context->refcount->addref($strPtr)', $src);
        $this->assertStringNotContainsString('lookupFunction(\'__string__separate\')', $src);
    }
}
