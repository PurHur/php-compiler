<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * set_file_buffer() — legacy alias of stream_set_read_buffer() (php-src streamsfuncs.c, issue #6107).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/streamsfuncs.c PHP_FUNCTION(set_file_buffer)
 */
final class set_file_buffer extends Internal
{
    public function execute(Frame $frame): void
    {
        stream_set_write_buffer_::run($frame, $this->getName());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return stream_set_write_buffer_::callJit($context, $this->getName(), ...$args);
    }
}
