<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35122 — global serialize(SplObjectStorage) must not SIGABRT on non-empty storage.
 */
final class Issue35122SosGlobalSerializeAotTest extends TestCase
{
    public function testCompileSerializeAvoidsFlatObjectHt(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/VM/SplObjectStorageJitHelper.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('#35122', $src);
        $this->assertStringContainsString('__compiler_serialize_value', $src);
        // Flat object HT path was the abort; must not remain in compileSerialize.
        $fnStart = strpos($src, 'public static function compileSerialize');
        $fnEnd = strpos($src, 'public static function compileLegacySerialize', $fnStart);
        $this->assertNotFalse($fnStart);
        $this->assertNotFalse($fnEnd);
        $body = substr($src, $fnStart, $fnEnd - $fnStart);
        $this->assertStringNotContainsString('__hashtable__setObjectAt', $body);
        $this->assertStringNotContainsString('SerializeSplObjectStorageNestedJitHelper', $body);
    }
}
