<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\Frame;

/** libxml_set_streams_context() — global stream context for libxml IO (php-src ext/libxml/libxml.c; #14495). */
final class libxml_set_streams_context extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_set_streams_context');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'libxml_set_streams_context() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $context = VmStreamContext::requireRepresentation(
            $frame->calledArgs[0],
            'libxml_set_streams_context',
            1
        );
        VmLibxml::setStreamsContext($context);
    }
}
