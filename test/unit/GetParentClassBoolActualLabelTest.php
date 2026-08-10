<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29631 — get_parent_class(false) TypeError prints false given (zend_zval_value_name)
 */
final class GetParentClassBoolActualLabelTest extends TestCase
{
    public function testGetParentClassFalseTrueVmLabels(): void
    {
        $code = file_get_contents(dirname(__DIR__) . '/repro/maintainer_run_20260810b/get_parent_class_false.php');
        self::assertNotFalse($code);
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_parent_class_false.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString(
            'TypeError: get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, false given',
            $out
        );
        self::assertStringContainsString(
            'TypeError: get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, true given',
            $out
        );
        self::assertStringNotContainsString('bool given', $out);
    }
}
