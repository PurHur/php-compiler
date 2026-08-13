<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::moveToAttributeNs() — namespaced attribute cursor (#19939). */
final class XmlReaderMoveToAttributeNs extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('moveToAttributeNs');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::moveToAttributeNs', 2);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::moveToAttributeNs()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('XMLReader::moveToAttributeNs(): Argument #1 ($name) must be of type string');
        }
        $nsVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nsVar->type) {
            throw new \TypeError('XMLReader::moveToAttributeNs(): Argument #2 ($namespace) must be of type string');
        }
        $name = $nameVar->toString();
        $namespace = $nsVar->toString();
        if ('' === $name) {
            throw new \ValueError('XMLReader::moveToAttributeNs(): Argument #1 ($name) cannot be empty');
        }
        if ('' === $namespace) {
            throw new \ValueError('XMLReader::moveToAttributeNs(): Argument #2 ($namespace) cannot be empty');
        }
        $ok = VmXmlReader::moveToAttributeNs($entry, $name, $namespace);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
