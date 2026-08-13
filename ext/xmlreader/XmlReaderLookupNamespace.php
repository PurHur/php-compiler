<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::lookupNamespace() — resolve prefix in current ns scope (#19396). */
final class XmlReaderLookupNamespace extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('lookupNamespace');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::lookupNamespace', 1);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::lookupNamespace()'
        );
        $prefixVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $prefixVar->type) {
            throw new \TypeError('XMLReader::lookupNamespace(): Argument #1 ($prefix) must be of type string');
        }
        $prefix = $prefixVar->toString();
        if ('' === $prefix) {
            throw new \ValueError('XMLReader::lookupNamespace(): Argument #1 ($prefix) cannot be empty');
        }
        $uri = VmXmlReader::lookupNamespace($entry, $prefix);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            if (null === $uri) {
                $ret->null();
            } else {
                $ret->string($uri);
            }
        });
    }
}
