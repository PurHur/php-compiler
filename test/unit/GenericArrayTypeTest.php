<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPCompiler\GenericArrayTypeSourceRewriter;
use PHPCompiler\GenericArrayTypeSpec;

final class GenericArrayTypeTest extends TestCase
{
    public function testEncodeListWithTypeParam(): void
    {
        $this->assertSame(
            '__phpc_list__int',
            GenericArrayTypeSpec::encodeList('int')
        );
    }

    public function testParseRoundTrip(): void
    {
        $name = GenericArrayTypeSpec::encodeArray('int', 'string');
        $spec = GenericArrayTypeSpec::tryParseDeclName($name);
        $this->assertNotNull($spec);
        $this->assertSame(GenericArrayTypeSpec::KIND_ARRAY, $spec->kind);
        $this->assertSame('int', $spec->keyType);
        $this->assertSame('string', $spec->valueType);
    }

    public function testRewriterListGeneric(): void
    {
        $src = '<?php function f(list<int> $x): void {}';
        $out = GenericArrayTypeSourceRewriter::rewrite($src);
        $this->assertStringContainsString('__phpc_list__int', $out);
        $this->assertStringNotContainsString('list<int>', $out);
    }

    public function testRewriterPreservesListDestructuring(): void
    {
        $src = '<?php list($a, $b) = [1, 2];';
        $out = GenericArrayTypeSourceRewriter::rewrite($src);
        $this->assertStringContainsString('list($a', $out);
    }

    public function testRewriterArrayGeneric(): void
    {
        $src = '<?php class C { public array<int, string> $m = []; }';
        $out = GenericArrayTypeSourceRewriter::rewrite($src);
        $this->assertStringContainsString('__phpc_array__int__string', $out);
    }
}
