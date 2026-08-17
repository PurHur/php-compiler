<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Inherited instance property defaults apply on subclass `new` (#31895).
 */
final class InheritedPropertyDefaults31895Test extends TestCase
{
    /**
     * @covers issue #31895
     */
    public function testInheritedTypedAndUntypedPropertyDefaults(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/issue_31895_inherited_prop_defaults.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_31895_inherited_prop_defaults.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("hi\nhi\nhi\nhi\nhi\n", $out);
    }
}
