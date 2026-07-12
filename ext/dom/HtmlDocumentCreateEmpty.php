<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** Dom\HTMLDocument::createEmpty() — VM (php-src ext/dom/html_document.c; #6506). */
final class HtmlDocumentCreateEmpty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createEmpty');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Dom\\HTMLDocument::createEmpty() requires VM context');
        $encoding = 'UTF-8';
        if (isset($frame->calledArgs[0])) {
            $encodingVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING !== $encodingVar->type) {
                throw new \TypeError('Dom\\HTMLDocument::createEmpty(): Argument #1 ($encoding) must be of type string');
            }
            $encoding = $encodingVar->toString();
        }
        $document = VmDomLiving::createEmpty($ctx, $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($document): void {
            $ret->copyFrom($document);
        });
    }
}
