<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Function-local static Closures must remain invokable across calls (#28039).
 *
 * Regression: frame teardown releaseRef()'d through DECLARE_FUNCTION_STATIC INDIRECT
 * aliases into Context/ClosureState storage, destroying ClosureState while the cell
 * still held the ObjectEntry (same shape as $this->prop Closures, #22656).
 */
final class FunctionStaticClosureCallableTest extends TestCase
{
    public function testFunctionStaticClosureCallableAcrossCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $c = null;
  if ($c === null) {
    $c = function ($n) { return $n * 2; };
  }
  return $c(3);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("6\n6\n", $this->runVm($code));
    }

    public function testNullCoalesceAssignStaticClosure(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $c = null;
  $c ??= function ($n) { return $n * 2; };
  return $c(3);
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("6\n6\n", $this->runVm($code));
    }

    public function testClosureBodyStaticClosure(): void
    {
        $code = <<<'PHP'
<?php
$outer = function () {
  static $c = null;
  if ($c === null) {
    $c = function ($n) { return $n * 2; };
  }
  return $c(3);
};
echo $outer(), "\n", $outer(), "\n";
PHP;
        $this->assertSame("6\n6\n", $this->runVm($code));
    }

    public function testIsCallableOnLaterCalls(): void
    {
        $code = <<<'PHP'
<?php
function f() {
  static $c = null;
  if ($c === null) {
    $c = function ($n) { return $n * 2; };
  }
  return is_callable($c) ? 'Y' : 'N';
}
echo f(), "\n", f(), "\n";
PHP;
        $this->assertSame("Y\nY\n", $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'function_static_closure_callable.php');
        ob_start();
        $rt->run($block);

        return (string) ob_get_clean();
    }
}
