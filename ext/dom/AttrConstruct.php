<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMAttr::__construct(string $name, string $value = "")
 * — orphaned attribute (php-src ext/dom/attr.c; #24631).
 */
final class AttrConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ATTR, 'DOMAttr::__construct()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMAttr::__construct() expects at least 1 argument, 0 given'
            );
        }
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMAttr::__construct()',
            0,
            $frame,
            'name'
        );
        $value = '';
        if (isset($frame->calledArgs[2])) {
            $value = $this->stringArg(
                $frame->calledArgs[2],
                'DOMAttr::__construct()',
                1,
                $frame,
                'value'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMAttr::__construct() requires VM context in this compiler build');
        }
        VmDom::constructAttr($frame->vmContext, $receiver, $name, $value);
    }
}
