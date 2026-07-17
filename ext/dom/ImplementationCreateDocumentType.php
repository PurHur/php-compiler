<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMImplementation::createDocumentType() — VM (#6140, #19797).
 *
 * php-src: ext/dom/domimplementation.stub.php
 *   createDocumentType(string $qualifiedName, string $publicId = "", string $systemId = "")
 */
final class ImplementationCreateDocumentType extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocumentType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMImplementation::createDocumentType() expects at least 1 argument, 0 given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'DOMImplementation::createDocumentType() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMImplementation::createDocumentType() requires VM context');
        }
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::createDocumentType()');
        $qualifiedName = $this->stringArg(
            $frame->calledArgs[1],
            'DOMImplementation::createDocumentType()',
            0,
            $frame,
            'qualifiedName'
        );
        $publicId = '';
        if ($argc >= 2) {
            $publicId = $this->stringArg(
                $frame->calledArgs[2],
                'DOMImplementation::createDocumentType()',
                1,
                $frame,
                'publicId'
            );
        }
        $systemId = '';
        if ($argc >= 3) {
            $systemId = $this->stringArg(
                $frame->calledArgs[3],
                'DOMImplementation::createDocumentType()',
                2,
                $frame,
                'systemId'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmDom::createDocumentType(
                $frame->vmContext,
                $qualifiedName,
                $publicId,
                $systemId
            ));
        }
    }
}
