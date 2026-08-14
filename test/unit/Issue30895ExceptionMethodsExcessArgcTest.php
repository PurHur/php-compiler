<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Exception/Error get* excess argc → ArgumentCountError (#30895).
 *
 * php-src: Zend/zend_exceptions.c
 */
final class Issue30895ExceptionMethodsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = <<<'PHP'
<?php
$e = new Exception('x', 0, new Exception('inner'));
foreach ([
  fn() => $e->getMessage(1),
  fn() => $e->getCode(1),
  fn() => $e->getFile(1),
  fn() => $e->getLine(1),
  fn() => $e->getTrace(1),
  fn() => $e->getTraceAsString(1),
  fn() => $e->getPrevious(1),
  fn() => (new Error('e', 9))->getCode(1),
  fn() => (new TypeError('t'))->getMessage(1),
] as $fn) {
  try {
    $fn();
    echo "ACCEPTED\n";
  } catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
  }
}
echo 'ok=', $e->getMessage(), ',', (new Error('e', 9))->getCode();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30895_exception_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: Exception::getMessage() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Exception::getPrevious() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Error::getCode() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Error::getMessage() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=x,9', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
