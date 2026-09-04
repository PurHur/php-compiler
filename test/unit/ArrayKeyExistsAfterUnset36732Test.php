<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT array_key_exists after string-key unset (#36732).
 *
 * php-src: ext/standard/array.c php_array_key_exists; Zend/zend_hash.c zend_hash_del.
 */
final class ArrayKeyExistsAfterUnset36732Test extends TestCase
{
    public function testVmArrayKeyExistsAfterUnset(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/array_key_exists_nested_unset_36732.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'array_key_exists_nested_unset_36732.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString("isset=false\n", $out);
        self::assertStringContainsString("ake=false\n", $out);
        self::assertStringContainsString("flat=false\n", $out);
        self::assertStringContainsString("nullval=true\n", $out);
        self::assertStringNotContainsString('ake=true', $out);
        self::assertStringNotContainsString('flat=true', $out);
    }
}
