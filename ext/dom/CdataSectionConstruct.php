<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMCdataSection::__construct(string $data)
 * — orphaned CDATA section (php-src ext/dom/cdatasection.c; #24631).
 */
final class CdataSectionConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_CDATA, 'DOMCdataSection::__construct()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            throw new \ArgumentCountError(
                'DOMCdataSection::__construct() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $data = $this->stringArg(
            $frame->calledArgs[1],
            'DOMCdataSection::__construct()',
            0,
            $frame,
            'data'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMCdataSection::__construct() requires VM context in this compiler build');
        }
        VmDom::constructCdataSection($frame->vmContext, $receiver, $data);
    }
}
