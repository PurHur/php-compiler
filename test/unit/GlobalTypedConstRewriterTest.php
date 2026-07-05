<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\GlobalTypedConstRewriter;
use PHPUnit\Framework\TestCase;

final class GlobalTypedConstRewriterTest extends TestCase
{
    /** @var false|string */
    private $savedProfile;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
    }

    protected function tearDown(): void
    {
        if (false === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

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

    public function testParseMarkerPayloadFinalPrefix(): void
    {
        self::assertSame(['string', true], GlobalTypedConstRewriter::parseMarkerPayload('final:string'));
        self::assertSame(['int', false], GlobalTypedConstRewriter::parseMarkerPayload('int'));
    }

    public function testRejectsFinalGlobalTypedConst(): void
    {
        $src = <<<'PHP'
<?php
final const string APP_NAME = 'alpha';
PHP;
        $this->expectException(\PhpParser\Error::class);
        $this->expectExceptionMessage(GlobalTypedConstRewriter::FINAL_GLOBAL_CONST_REJECT_MESSAGE);
        GlobalTypedConstRewriter::rewrite($src);
    }

    public function testRejectsFinalNamespaceTypedConst(): void
    {
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
