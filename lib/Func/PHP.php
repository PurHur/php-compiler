<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Func;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Func;
use PHPCompiler\Frame;
use PHPCompiler\Block;
use PHPCompiler\VM\Context;

final class PHP extends Func {

    public Block $block;
    /** #[\Deprecated] metadata when declared on this function/method (#3569). */
    public ?DeprecatedMetadata $deprecated = null;
    /** Compile-time docblock + declaration site for ReflectionFunction (#22144 / #7358). */
    public ?SourceLocation $sourceLocation = null;
    /** @var list<\PHPCompiler\Compiler\ParameterMetadata> */
    public array $parameterMetadata = [];
    /** @var list<string> */
    public array $attributeNames = [];
    /** @var list<AttributeEntry> */
    public array $attributeEntries = [];

    public function __construct(string $name, Block $block) {
        parent::__construct($name);
        $this->block = $block;
    }

    public function getFrame(Context $context, ?Frame $frame = null): Frame {
        return $this->block->getFrame($context, $frame);
    }

}
