<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * XMLReader::readString() — concatenated text content of the current node (#19411).
 *
 * php-src zim_XMLReader_readString / xmlTextReaderReadString.
 */
final class XmlReaderReadString extends XmlReaderClassMethod
{
    public function __construct()
    {
        parent::__construct('readString');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'XMLReader::readString', 0);
        $entry = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $text = VmXmlReader::readString($entry);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($text): void {
            $ret->string($text);
        });
    }
}
