<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

use PHPCfg\Op\Expr\Array_;
use PHPCfg\Op\Expr\StaticCall;
use PHPCfg\Op\Expr\StaticPropertyFetch;
use PHPCfg\Op\Terminal\StaticVar;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPUnit\Framework\TestCase;

class AnalyzerTest extends TestCase
{
    public function testComputeStaticArraySizeNullKeys(): void
    {
        $analyzer = new Analyzer();
        $this->assertEquals(3, $analyzer->computeStaticArraySize($this->makeOperand([null, null, null])));
    }

    public function testComputeStaticArraySizeNonNullKeys(): void
    {
        $analyzer = new Analyzer();
        $keys = $this->makeOperand([
            null,
            new Operand\Literal(1),
            new Operand\Literal(2),
        ]);
        $this->assertEquals(3, $analyzer->computeStaticArraySize($keys));
    }

    public function testComputeStaticArraySizeDuplicatedKeys(): void
    {
        $analyzer = new Analyzer();
        $keys = $this->makeOperand([
            null,
            new Operand\Literal(0),
        ]);
        $this->assertEquals(1, $analyzer->computeStaticArraySize($keys));
    }

    public function testComputeStaticArraySizeSkippedKeys(): void
    {
        $analyzer = new Analyzer();
        $keys = $this->makeOperand([
            null,
            new Operand\Literal(2),
        ]);
        $this->assertEquals(3, $analyzer->computeStaticArraySize($keys));
    }

    public function testComputeStaticArraySizeStaticCallWriteOp(): void
    {
        $analyzer = new Analyzer();
        $class = new Operand\Literal('Foo');
        $class->type = Type::string();
        $name = new Operand\Literal('bar');
        $name->type = Type::string();
        $staticCall = new StaticCall($class, $name, []);

        $this->assertNull($analyzer->computeStaticArraySize($staticCall->result));
    }

    public function testComputeStaticArraySizeStaticPropertyFetchWriteOp(): void
    {
        $analyzer = new Analyzer();
        $class = new Operand\Literal('Foo');
        $class->type = Type::string();
        $name = new Operand\Literal('bar');
        $name->type = Type::string();
        $fetch = new StaticPropertyFetch($class, $name);

        $this->assertNull($analyzer->computeStaticArraySize($fetch->result));
    }

    public function testCanEscapeFunctionStaticArrayInitOperand(): void
    {
        $analyzer = new Analyzer();
        $init = $this->makeOperand([null, null]);
        $name = new Operand\Literal('a');
        $name->type = Type::string();
        $var = new Operand\Variable($name);
        new StaticVar($var, null, $init);

        $this->assertFalse($analyzer->canEscape($init));
    }

    private function makeOperand(array $keys): Operand
    {
        $values = [];
        foreach ($keys as $key => $value) {
            if (null === $value) {
                $keys[$key] = new Operand\NullOperand();
            } else {
                $value->type = Type::int();
            }
            $values[] = new Operand\NullOperand();
        }
        $result = new Operand\Temporary();
        $result->addWriteOp(new Array_($keys, $values, []));

        return $result;
    }
}
