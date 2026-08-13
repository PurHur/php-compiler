<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::expand() — materialize current node (+ descendants) as DOM (php-src zim_XMLReader_expand; #19394).
 */
final class XmlReaderExpand extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('expand');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'XMLReader::expand', 0, 1);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $baseNode = null;
        if (\count($frame->calledArgs) >= 2) {
            $baseVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $baseVar->type) {
                if (Variable::TYPE_OBJECT !== $baseVar->type) {
                    throw new \TypeError(
                        'XMLReader::expand(): Argument #1 ($baseNode) must be of type ?DOMNode, '
                        .\PHPCompiler\ext\dom\VmDom::typeLabel($baseVar).' given'
                    );
                }
                $candidate = $baseVar->toObject();
                if (!\PHPCompiler\ext\dom\VmDom::isDomNode($candidate)) {
                    throw new \TypeError(
                        'XMLReader::expand(): Argument #1 ($baseNode) must be of type ?DOMNode, '
                        .$candidate->class->name.' given'
                    );
                }
                $baseNode = $candidate;
            }
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('XMLReader::expand() requires VM context');
        $node = VmXmlReader::expand($ctx, $entry, $baseNode, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($node): void {
            if (false === $node) {
                $ret->bool(false);
            } else {
                $ret->object($node);
            }
        });
    }
}
