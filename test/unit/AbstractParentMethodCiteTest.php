<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Zend cites the declaring abstract parent, not the incomplete child (#30022).
 *
 * php-src: Zend/zend_inheritance.c — zend_verify_abstract_class residual list.
 */
final class AbstractParentMethodCiteTest extends TestCase
{
    public function testEvalIncompleteChildCitesParentMethod(): void
    {
        $code = <<<'PHP'
<?php
abstract class A { abstract function f(); }
eval('class B extends A {}');
PHP;
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_abs_cite_30022.php').' 2>&1';
        file_put_contents('/tmp/phpc_abs_cite_30022.php', $code);
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_abs_cite_30022.php');

        $this->assertStringContainsString('(A::f)', $output);
        $this->assertStringNotContainsString('(B::f)', $output);
    }

    public function testEvalIncompleteChildCitesGrandparentThroughMid(): void
    {
        $code = <<<'PHP'
<?php
abstract class A { abstract function f(); }
abstract class Mid extends A {}
eval('class B extends Mid {}');
PHP;
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_abs_cite_grand_30022.php').' 2>&1';
        file_put_contents('/tmp/phpc_abs_cite_grand_30022.php', $code);
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_abs_cite_grand_30022.php');

        $this->assertStringContainsString('(A::f)', $output);
        $this->assertStringNotContainsString('(Mid::f)', $output);
        $this->assertStringNotContainsString('(B::f)', $output);
    }

    public function testInterfaceResidualStillCitesInterface(): void
    {
        $code = <<<'PHP'
<?php
interface I { function f(); }
eval('class B implements I {}');
PHP;
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_iface_cite_30022.php').' 2>&1';
        file_put_contents('/tmp/phpc_iface_cite_30022.php', $code);
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_iface_cite_30022.php');

        $this->assertStringContainsString('(I::f)', $output);
    }

    public function testTraitAbstractStillCitesUsingClass(): void
    {
        $code = <<<'PHP'
<?php
trait T { abstract function f(); }
class C { use T; }
PHP;
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_trait_cite_30022.php').' 2>&1';
        file_put_contents('/tmp/phpc_trait_cite_30022.php', $code);
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_trait_cite_30022.php');

        $this->assertStringContainsString('(C::f)', $output);
    }
}
