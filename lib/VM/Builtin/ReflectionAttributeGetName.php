<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionAttribute::getName() — VM (#1936). */
final class ReflectionAttributeGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args); $calledArgs[0] is $this (#30896)
        $this->requireExactUserArgCount($frame, 'ReflectionAttribute::getName', 0);
        $receiver = ReflectionSupport::requireReflectionAttribute($frame, $frame->calledArgs[0]);
        $nameVar = $receiver->getProperty(ReflectionSupport::PROP_ATTR_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('ReflectionAttribute missing name');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($nameVar->toString());
        }
    }
}
