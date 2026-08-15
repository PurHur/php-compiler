<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * show_source() — legacy alias of highlight_file() (php-src url.c).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30689; peer highlight_string #30723).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(show_source)
 */
final class show_source extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30689; ext/standard/basic_functions.stub.php).
        $this->requireArgCountRange($frame, $this->getName(), 1, 2);
        highlight_file::run($frame, $this->getName());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30689; peer #30723 / #27763).
        if (!$this->requireArgCountRangeJit($context, $args, $this->getName(), 1, 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitHighlight::highlightFile($context, $this->getName(), ...$args);
    }
}
