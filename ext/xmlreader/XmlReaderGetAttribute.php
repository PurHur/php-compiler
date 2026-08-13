<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::getAttribute() — current element attribute lookup (#6135). */
final class XmlReaderGetAttribute extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::getAttribute', 1);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::getAttribute()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('XMLReader::getAttribute(): Argument #2 ($name) must be of type string');
        }
        $value = VmXmlReader::getAttribute($entry, $nameVar->toString());
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($value): void {
            if (null === $value) {
                $ret->null();
            } else {
                $ret->string($value);
            }
        });
    }
}
