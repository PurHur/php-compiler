<?php

declare(strict_types=1);

use PHPCompiler\Ast\InOperatorDesugar;
use PHPUnit\Framework\TestCase;

/** @group compliance */
final class InOperatorDesugarTest extends TestCase
{
    public function testDesugarDoesNotRewriteInfixIn(): void
    {
        $code = <<<'PHP'
<?php
echo 1 in [1, 2];
PHP;
        $this->assertSame($code, InOperatorDesugar::desugar($code));
        $this->assertStringContainsString(' in ', InOperatorDesugar::desugar($code));
        $this->assertStringNotContainsString('__phpcLangIn', InOperatorDesugar::desugar($code));
    }

    public function testDesugarLeavesForeachAndInArray(): void
    {
        $code = <<<'PHP'
<?php
foreach ($a as $k => $v) {
    var_dump(in_array($v, [1, 2], true));
}
PHP;
        $this->assertSame($code, InOperatorDesugar::desugar($code));
    }
}
