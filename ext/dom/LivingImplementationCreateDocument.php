<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Dom\Implementation::createDocument() — returns Dom\XMLDocument (php-src domimplementation.c; #20910).
 */
final class LivingImplementationCreateDocument extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocument');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Implementation::createDocument() requires VM context');
        }
        self::receiver($frame, VmDomLiving::CLASS_IMPLEMENTATION, 'Dom\\Implementation::createDocument()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 2) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Implementation::createDocument() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Implementation::createDocument() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'Dom\\Implementation::createDocument()', 0);
        $qualifiedName = $this->stringArg($frame->calledArgs[2], 'Dom\\Implementation::createDocument()', 1);
        $doctype = null;
        if ($argc >= 3) {
            $doctype = self::optionalDocumentType($frame->calledArgs[3]);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmDomLiving::createDocument(
                $frame->vmContext,
                $namespace,
                $qualifiedName,
                $doctype
            ));
        }
    }

    private static function optionalDocumentType(Variable $var): ?\PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(
                'Dom\\Implementation::createDocument(): Argument #3 ($doctype) must be of type Dom\\DocumentType or null'
            );
        }
        $entry = $var->toObject();
        if (!VmDom::isDocumentType($entry)) {
            throw new \TypeError(
                'Dom\\Implementation::createDocument(): Argument #3 ($doctype) must be of type Dom\\DocumentType or null'
            );
        }

        return $entry;
    }
}
