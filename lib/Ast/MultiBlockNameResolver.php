<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\ErrorHandler;
use PhpParser\NodeVisitor\NameResolver;

/**
 * NameResolver with multi-block namespace alias restore (#4425).
 */
final class MultiBlockNameResolver extends NameResolver
{
    public function __construct(?ErrorHandler $errorHandler = null, array $options = [])
    {
        parent::__construct($errorHandler, $options);
        $ref = new \ReflectionProperty(NameResolver::class, 'nameContext');
        $ref->setAccessible(true);
        $ref->setValue(
            $this,
            new MultiBlockNameContext($errorHandler ?? new ErrorHandler\Throwing())
        );
    }
}
