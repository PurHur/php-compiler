<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Func;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Func;
use PHPCompiler\Frame;
use PHPCompiler\Block;
use PHPCompiler\VM\Context;

final class PHP extends Func {

    public Block $block;
    /** #[\Deprecated] metadata when declared on this function/method (#3569). */
    public ?DeprecatedMetadata $deprecated = null;

    public function __construct(string $name, Block $block) {
        parent::__construct($name);
        $this->block = $block;
    }

    public function getFrame(Context $context, ?Frame $frame = null): Frame {
        return $this->block->getFrame($context, $frame);
    }

}
