<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\InOperatorDesugar;
use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Reject `$needle in $haystack` — php-src has no `in` operator (#31158). */
final class InOperatorSyntaxRejectorTest extends TestCase
{
    public function testRejectsInfixIn(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(InOperatorDesugar::PARSE_ERROR_UNEXPECTED_IN);

        InOperatorDesugar::reject('<?php echo 1 in [1,2] ? "yes" : "no";', 'test.php');
    }

    public function testSyntaxErrorLine(): void
    {
        $error = InOperatorDesugar::syntaxError(<<<'PHP'
<?php
$a = 1;
echo 1 in [1, 2];
PHP
        );
        self::assertNotNull($error);
        self::assertSame(3, $error['line']);
        self::assertSame(InOperatorDesugar::PARSE_ERROR_UNEXPECTED_IN, $error['message']);
    }

    public function testNoOpWithoutInToken(): void
    {
        $code = '<?php echo 1 + 2;';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }

    public function testAllowsForeach(): void
    {
        $code = '<?php foreach ($a as $k => $v) { echo $v; }';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }

    public function testAllowsInArray(): void
    {
        $code = '<?php var_dump(in_array(1, [1, 2], true));';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }

    public function testAllowsFunctionNamedIn(): void
    {
        $code = '<?php function in($x) { return $x; } echo in(1);';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }

    public function testAllowsObjectPropertyIn(): void
    {
        $code = '<?php $o = new stdClass; $o->in = 1; echo $o->in;';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }

    public function testAllowsClassConstIn(): void
    {
        $code = '<?php class C { const in = 1; } echo C::in;';
        self::assertSame($code, InOperatorDesugar::reject($code, 'test.php'));
    }
}
