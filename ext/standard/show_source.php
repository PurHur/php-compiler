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
    private highlight_file $delegate;

    public function __construct()
    {
        parent::__construct('show_source');
        $this->delegate = new highlight_file();
    }

    public function execute(Frame $frame): void
    {
        $this->delegate->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return $this->delegate->call($context, ...$args);
    }
}
