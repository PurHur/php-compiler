<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\GlobalTypedConstRewriter;
use PHPUnit\Framework\TestCase;

final class GlobalTypedConstRewriterTest extends TestCase
{
    public function testRewritesFileScopeTypedConst(): void
    {
        $src = <<<'PHP'
<?php
const string GLOBAL_NAME = 'alpha';
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:string*/ const GLOBAL_NAME', preg_replace('/\s+/', ' ', $out));
    }

    public function testDoesNotRewriteClassTypedConst(): void
    {
        $src = <<<'PHP'
<?php
class C { public const string X = 'a'; }
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringNotContainsString('phpc-global-typed-const', $out);
        self::assertStringContainsString('const string X', $out);
    }

    public function testRewritesNamespaceBlockTypedConst(): void
    {
        $src = <<<'PHP'
<?php
namespace N {
    const string NS_NAME = 'beta';
}
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:string*/ const NS_NAME', preg_replace('/\s+/', ' ', $out));
    }

    public function testRewritesFinalFileScopeTypedConst(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            self::markTestSkipped('final global typed constants require PHP 8.4.0+ target');
        }
        $src = <<<'PHP'
<?php
final const string APP_NAME = 'alpha';
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:final:string*/ const APP_NAME', preg_replace('/\s+/', ' ', $out));
    }

    public function testRewritesFinalNamespaceBlockTypedConst(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            self::markTestSkipped('final global typed constants require PHP 8.4.0+ target');
        }
        $src = <<<'PHP'
<?php
namespace FinalTyped {
    final const string NS_NAME = 'beta';
}
PHP;
        $out = GlobalTypedConstRewriter::rewrite($src);
        self::assertStringContainsString('/*phpc-global-typed-const:final:string*/ const NS_NAME', preg_replace('/\s+/', ' ', $out));
    }

    public function testParseMarkerPayloadFinalPrefix(): void
    {
        self::assertSame(['string', true], GlobalTypedConstRewriter::parseMarkerPayload('final:string'));
        self::assertSame(['int', false], GlobalTypedConstRewriter::parseMarkerPayload('int'));
    }

    public function testRejectsFinalGlobalTypedConstWhenGateOff(): void
    {
        if (\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            self::markTestSkipped('final global typed constants enabled on PHP 8.4+ target');
        }
        $src = <<<'PHP'
<?php
final const string APP_NAME = 'alpha';
PHP;
        $this->expectException(\PhpParser\Error::class);
        $this->expectExceptionMessage(GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
        GlobalTypedConstRewriter::rewrite($src);
    }

    public function testRejectsFinalNamespaceTypedConstWhenGateOff(): void
    {
        if (\PHPCompiler\CompilerVersion::supportsFinalGlobalTypedConstants()) {
            self::markTestSkipped('final global typed constants enabled on PHP 8.4+ target');
        }
        $src = <<<'PHP'
<?php
namespace N {
    final const string NS_NAME = 'beta';
}
PHP;
        $this->expectException(\PhpParser\Error::class);
        $this->expectExceptionMessage(GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
        GlobalTypedConstRewriter::rewrite($src);
    }
}
