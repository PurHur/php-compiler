<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * http_get_last_response_headers() — last HTTP wrapper response header list (ext/standard/basic_functions.c, #7236).
 */
class http_get_last_response_headers extends Internal
{
    public function __construct(?string $name = null)
    {
        parent::__construct($name ?? 'http_get_last_response_headers');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException($this->getName().'() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $headers = VmHttpLastResponseHeaders::get();
        if (null === $headers) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($headers));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException($this->getName().'() takes no arguments');
        }

        return JitHttpLastResponseHeaders::invoke($context);
    }
}
