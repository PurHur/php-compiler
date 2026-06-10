<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_register_wrapper() — PHP alias of stream_wrapper_register() (ext/standard/streams.c PHP_FALIAS; #6805). */
final class stream_register_wrapper extends Internal
{
    private stream_wrapper_register $delegate;

    public function __construct()
    {
        parent::__construct('stream_register_wrapper');
        $this->delegate = new stream_wrapper_register();
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
