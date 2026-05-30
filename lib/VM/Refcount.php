<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

class Refcount {
    /** @var int Number of zvals sharing this HashTable (Zend GC refcount). */
    public int $refcount = 1;

    public function addRef(): void {
        ++$this->refcount;
    }

    public function delRef(): void {
        if ($this->refcount > 0) {
            --$this->refcount;
        }
    }

    public function needsSeparate(): bool {
        return $this->refcount > 1;
    }

    public function assertSeparated(): void {
        if ($this->needsSeparate()) {
            throw new \LogicException('Refcount is > 1, but was asserted to be 1');
        }
    }

}