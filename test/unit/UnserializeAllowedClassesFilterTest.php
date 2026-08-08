<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #29065 — unserialize() allowed_classes filters nested/array objects (var_unserializer.re).
 */
final class UnserializeAllowedClassesFilterTest extends TestCase
{
    public function testVmAllowListAndFalseMatchZendClassNames(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_29065_unserialize_allowed_classes.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_29065_unserialize_allowed_classes.php');
        $this->assertNotNull($block);
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "Allowed\n__PHP_Incomplete_Class\n__PHP_Incomplete_Class\n__PHP_Incomplete_Class\nOuter\n__PHP_Incomplete_Class\n",
            $out
        );
    }
}
