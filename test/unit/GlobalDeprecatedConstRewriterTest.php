<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\GlobalDeprecatedConstRewriter;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

final class GlobalDeprecatedConstRewriterTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        parent::tearDown();
    }

    public function testRewritesGeneralGlobalConstAttrsOn85Profile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $src = <<<'PHP'
<?php
#[Marker]
const FOO = 42;
PHP;
        $out = GlobalDeprecatedConstRewriter::rewrite($src);
        self::assertStringContainsString('phpc-global-const-attrs:', $out);
        self::assertStringNotContainsString('#[Marker]', $out);
        self::assertStringContainsString('const FOO = 42', $out);
        if (!preg_match(GlobalDeprecatedConstRewriter::ATTRS_MARKER_PATTERN, $out, $m)) {
            self::fail('attrs marker missing');
        }
        $groups = GlobalDeprecatedConstRewriter::parseAttrsMarkerPayload($m[1]);
        self::assertCount(1, $groups);
        self::assertSame('Marker', $groups[0]->attrs[0]->name->toString());
    }

    public function testLeavesGeneralAttrsOn84ProfileForParserReject(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $src = <<<'PHP'
<?php
#[Marker]
const FOO = 42;
PHP;
        self::assertSame($src, GlobalDeprecatedConstRewriter::rewrite($src));
    }

    public function testRewritesDeprecatedGlobalConstOnForwardProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $src = <<<'PHP'
<?php
#[\Deprecated(since: '8.4')]
const FOO = 42;
PHP;
        $out = GlobalDeprecatedConstRewriter::rewrite($src);
        self::assertStringContainsString('phpc-global-deprecated-const:since=8.4', $out);
        self::assertStringNotContainsString('#[', $out);
        self::assertStringContainsString('const FOO = 42', $out);
    }

    public function testLeavesClassConstAttributesUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $src = <<<'PHP'
<?php
class C {
    #[\Deprecated(since: '8.4')]
    public const X = 1;
}
PHP;
        self::assertSame($src, GlobalDeprecatedConstRewriter::rewrite($src));
    }

    public function testReferenceProfileSyntaxError(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $src = <<<'PHP'
<?php
#[\Deprecated(since: '8.4')]
const FOO = 42;
PHP;
        $error = GlobalDeprecatedConstRewriter::referenceProfileSyntaxError($src);
        self::assertNotNull($error);
        self::assertSame('syntax error, unexpected token "const"', $error['message']);
    }

    public function testParseMarkerPayload(): void
    {
        $meta = GlobalDeprecatedConstRewriter::parseMarkerPayload('since=8.4');
        self::assertNotNull($meta);
        self::assertSame('8.4', $meta->since);
        self::assertTrue($meta->emitsRuntimeNotice());
    }
}
