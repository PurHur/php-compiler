<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6741 */
final class DnfParamIntersectionClassRegressionTest extends TestCase
{
    public function testIntersectionClassUnionParamCompilesAndAcceptsImplementingClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I1 {}
interface I2 {}
class C implements I1, I2 {}

class DnfParam {
    public function m((I1&I2)|C $x): void {
        echo get_class($x), "\n";
    }
}

(new DnfParam())->m(new C());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dnf_param_intersection_class.php'));
        $this->assertSame("C\n", ob_get_clean());
    }

    public function testIntersectionClassUnionParamRejectsIncompatibleValue(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I1 {}
interface I2 {}
class C implements I1, I2 {}

class DnfParam {
    public function m((I1&I2)|C $x): void {}
}

(new DnfParam())->m([]);
PHP;
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Argument must be of type (I1&I2)|C, array given');
        $runtime->run($runtime->parseAndCompile($code, 'dnf_param_intersection_class_type_error.php'));
    }
}
