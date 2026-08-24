<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Guards #34464 — multi-prop object foreach boxes inside each case before merge. */
final class Issue34464ForeachObjectPropsAotTest extends TestCase
{
    public function testFetchBoxesInsidePropertyCases(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmObjectPropertyForeach.php');
        $this->assertStringContainsString('emitPropertyValueAtIndex', $source);
        $this->assertStringContainsString('boxFetchedPropertyIntoValueBox($destSlot, $fetched)', $source);
        // Post-merge use of a case-local fetched slot was the dominate-uses bug.
        $this->assertStringNotContainsString(
            "return \$fetched;\n    }\n\n    private static function emitPropertyNameAtIndex",
            $source
        );
    }
}
