<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** Dom\XMLDocument::createEmpty() — VM (php-src ext/dom/xml_document.c; #19581). */
final class XmlDocumentCreateEmpty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createEmpty');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Dom\\XMLDocument::createEmpty() requires VM context');
        $version = '1.0';
        $encoding = 'UTF-8';
        if (isset($frame->calledArgs[0])) {
            $versionVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING !== $versionVar->type) {
                throw new \TypeError('Dom\\XMLDocument::createEmpty(): Argument #1 ($version) must be of type string');
            }
            $version = $versionVar->toString();
        }
        if (isset($frame->calledArgs[1])) {
            $encodingVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encodingVar->type) {
                throw new \TypeError('Dom\\XMLDocument::createEmpty(): Argument #2 ($encoding) must be of type string');
            }
            $encoding = $encodingVar->toString();
        }
        $document = VmDomLiving::createXmlEmpty($ctx, $version, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($document): void {
            $ret->copyFrom($document);
        });
    }
}
