<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** XMLReader::isValid() — well-formedness flag (#6135). */
final class XmlReaderIsValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isValid');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('XMLReader::isValid() expects at least 1 argument, 0 given');
        }
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
