<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * show_source() — legacy alias of highlight_file() (php-src url.c).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(show_source)
 */
final class show_source extends Internal
{
    public function execute(Frame $frame): void
    {
        highlight_file::run($frame, $this->getName());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitHighlight::highlightFile($context, $this->getName(), ...$args);
    }
}
