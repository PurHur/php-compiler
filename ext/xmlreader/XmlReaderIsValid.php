<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::isValid() — well-formedness flag (#6135). */
final class XmlReaderIsValid extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('isValid');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::isValid', 0);
        $entry = VmXmlReader::requireReader(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'XMLReader::isValid()'
        );
        $valid = VmXmlReader::isValid($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($valid): void {
            $ret->bool($valid);
        });
    }
}
