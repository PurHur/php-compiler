<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMImplementation::createDocument() — VM (#6140).
 *
 * At most 3 user args — Zend ArgumentCountError (#31090; missed by #31011).
 */
final class ImplementationCreateDocument extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocument');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMImplementation::createDocument', 3);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMImplementation::createDocument() requires VM context');
        }
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::createDocument()');
        $namespace = isset($frame->calledArgs[1])
            ? $this->nullableStringArg($frame->calledArgs[1], 'DOMImplementation::createDocument()', 0)
            : null;
        $qualifiedName = isset($frame->calledArgs[2])
            ? $this->stringArg(
                $frame->calledArgs[2],
                'DOMImplementation::createDocument()',
                1,
                $frame,
                'qualifiedName'
            )
            : '';
        $doctype = isset($frame->calledArgs[3])
            ? self::optionalDocumentType($frame->calledArgs[3])
            : null;
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmDom::createDocument(
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
                'DOMImplementation::createDocument(): Argument #3 ($doctype) must be of type DOMDocumentType or null'
            );
        }
        $entry = $var->toObject();
        if (!VmDom::isDocumentType($entry)) {
            throw new \TypeError(
                'DOMImplementation::createDocument(): Argument #3 ($doctype) must be of type DOMDocumentType or null'
            );
        }

        return $entry;
    }
}
