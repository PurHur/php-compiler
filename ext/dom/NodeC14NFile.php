<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::C14NFile() — canonical XML to file (php-src ext/dom/node.c; #14409). */
final class NodeC14NFile extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('C14NFile');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::C14NFile()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('DOMNode::C14NFile() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::C14NFile() requires VM context in this compiler build');
        }
        $uri = $this->stringArg($frame->calledArgs[1], 'DOMNode::C14NFile()', 0, $frame, 'uri');
        [$exclusive, $withComments, $xpath, $nsPrefixes] = NodeC14N::parseC14NArgs(
            $frame,
            2,
            'DOMNode::C14NFile()'
        );
        $bytes = VmDom::c14nFile(
            $frame->vmContext,
            $node,
            $uri,
            $exclusive,
            $withComments,
            $xpath,
            $nsPrefixes,
            $frame
        );
        if (null !== $frame->returnVar) {
            if (false === $bytes) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->int($bytes);
            }
        }
    }
}
