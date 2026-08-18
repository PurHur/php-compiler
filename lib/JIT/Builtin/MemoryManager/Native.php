<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT/Builtin/MemoryManager/Native.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Builtin\MemoryManager;

use PHPCompiler\JIT\Builtin\MemoryManager;
use PHPCompiler\JIT\LibcExtern;

class Native extends MemoryManager {

    public function register(): void {
        parent::register();
        // malloc/realloc/free after LibcExtern always-on drop (#32273).
        LibcExtern::ensureMallocFamily($this->context);
    } 

    public function implement(): void {
        // Todo
        $fn___c4ca4238a0b923820dcc509a6f75849b = $this->context->lookupFunction('__mm__malloc');
    $block___c4ca4238a0b923820dcc509a6f75849b = $fn___c4ca4238a0b923820dcc509a6f75849b->appendBasicBlock('main');
    $this->context->builder->positionAtEnd($block___c4ca4238a0b923820dcc509a6f75849b);
    $size = $fn___c4ca4238a0b923820dcc509a6f75849b->getParam(0);
    
    $result = $this->context->builder->call(
                        $this->context->lookupFunction('malloc') , 
                        $size
                        
                    );
    $this->context->builder->returnValue($result);
    
    $this->context->builder->clearInsertionPosition();
        $fn___eccbc87e4b5ce2fe28308fd9f2a7baf3 = $this->context->lookupFunction('__mm__realloc');
    $block___eccbc87e4b5ce2fe28308fd9f2a7baf3 = $fn___eccbc87e4b5ce2fe28308fd9f2a7baf3->appendBasicBlock('main');
    $this->context->builder->positionAtEnd($block___eccbc87e4b5ce2fe28308fd9f2a7baf3);
    $void = $fn___eccbc87e4b5ce2fe28308fd9f2a7baf3->getParam(0);
    $size = $fn___eccbc87e4b5ce2fe28308fd9f2a7baf3->getParam(1);
    
    $result = $this->context->builder->call(
                        $this->context->lookupFunction('realloc') , 
                        $void
                        , $size
                        
                    );
    $this->context->builder->returnValue($result);
    
    $this->context->builder->clearInsertionPosition();
        $fn___e4da3b7fbbce2345d7772b0674a318d5 = $this->context->lookupFunction('__mm__free');
    $block___e4da3b7fbbce2345d7772b0674a318d5 = $fn___e4da3b7fbbce2345d7772b0674a318d5->appendBasicBlock('main');
    $this->context->builder->positionAtEnd($block___e4da3b7fbbce2345d7772b0674a318d5);
    $void = $fn___e4da3b7fbbce2345d7772b0674a318d5->getParam(0);
    
    $this->context->builder->call(
                    $this->context->lookupFunction('free') , 
                    $void
                    
                );
    $this->context->builder->returnVoid();
    
    $this->context->builder->clearInsertionPosition();
    }

}
