<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::getAttributeNs() — namespaced attribute lookup (#19412). */
final class XmlReaderGetAttributeNs extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNs');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::getAttributeNs', 2);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::getAttributeNs()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('XMLReader::getAttributeNs(): Argument #1 ($name) must be of type string');
        }
        $nsVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nsVar->type) {
            throw new \TypeError('XMLReader::getAttributeNs(): Argument #2 ($namespace) must be of type string');
        }
        $name = $nameVar->toString();
        $namespace = $nsVar->toString();
        if ('' === $name) {
            throw new \ValueError('XMLReader::getAttributeNs(): Argument #1 ($name) cannot be empty');
        }
        if ('' === $namespace) {
            throw new \ValueError('XMLReader::getAttributeNs(): Argument #2 ($namespace) cannot be empty');
        }
        $value = VmXmlReader::getAttributeNs($entry, $name, $namespace);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($value): void {
            if (null === $value) {
                $ret->null();
            } else {
                $ret->string($value);
            }
        });
    }
}
