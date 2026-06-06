<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\JitMcjitEmbed */
final class JitMcjitEmbedTest extends TestCase
{
    public function testPadsEmptyUserClassBodyForMcjit(): void
    {
        $in = <<<'PHP'
<?php
class Foo {}
$o = new Foo();
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringNotContainsString("class Foo {}\n", $out);
    }

    public function testLeavesNonEmptyClassUnchanged(): void
    {
        $in = <<<'PHP'
<?php
class Foo { public int $x = 1; }
PHP;
        $this->assertSame($in, JitMcjitEmbed::prepareClassless($in));
    }

    public function testInjectsBootstrapWhenNoUserClass(): void
    {
        $in = '<?php echo 1;';
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
    }

    public function testInjectsBootstrapForEnumOnlyScript(): void
    {
        $in = <<<'PHP'
<?php
enum U { case A; case B; }
echo count(U::cases());
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString('enum U', $out);
    }
}
