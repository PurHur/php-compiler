<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

class HashTableTest extends TestCase
{
    public function testAdd(): void
    {
        $ht = new HashTable();
        $var = $this->int(123);
        $result = $ht->add('test', $var);

        $this->assertNotNull($result);
        $this->assertTrue($result->identicalTo($var));
    }

    public function testAddCopiesvariable(): void
    {
        $ht = new HashTable();
        $var = $this->int(123);

        $result = $ht->add('test', $var);

        $var->int(456);

        $this->assertNotNull($result);
        $this->assertFalse($result->identicalTo($var));
    }

    public function testAddThenFind(): void
    {
        $ht = new HashTable();

        $var = $this->int(123);
        $ht->add('test', $var);

        $result = $ht->find('test');
        $this->assertNotNull($result);
        $this->assertTrue($result->identicalTo($var));
    }

    public function testAddTwoElements(): void
    {
        $ht = new HashTable();

        $a = $this->int(123);
        $ht->add('test', $a);

        $b = $this->int(456);
        $ht->add('other', $b);

        $resulta = $ht->find('test');
        $this->assertNotNull($resulta);
        $this->assertTrue($resulta->identicalTo($a));

        $resultb = $ht->find('other');
        $this->assertNotNull($resultb);
        $this->assertTrue($resultb->identicalTo($b));
    }

    public function testAddThenUpdateThenFind(): void
    {
        $ht = new HashTable();
        $var = $this->int(123);
        $ht->add('test', $var);

        $var2 = $this->int(456);
        $ht->update('test', $var2);

        $result = $ht->find('test');
        $this->assertNotNull($result);
        $this->assertTrue($result->identicalTo($var2));
    }

    public function testNumericKeyAppend(): void
    {
        $ht = new HashTable();
        $vars = [
            $this->int(1),
            $this->int(2),
            $this->int(3),
            $this->int(4),
        ];
        foreach ($vars as $var) {
            $ht->append($var);
        }
        foreach ($vars as $idx => $var) {
            $result = $ht->findIndex($idx);

            $this->assertNotNull($result, 'ht->findIndex failed for index '.$idx);
            $this->assertTrue($result->identicalTo($var));
        }
    }

    public function testResize(): void
    {
        $ht = new HashTable();
        $vars = [];
        for ($i = 0; $i < HashTable::MIN_SIZE + 1; ++$i) {
            $vars[$i] = $var = $this->int($i + 1);
            $ht->append($var);
        }
        // resize triggers during MIN_SIZE + 1
        for ($i = 0; $i < HashTable::MIN_SIZE + 1; ++$i) {
            $result = $ht->findIndex($i);
            $this->assertNotNull($result, 'ht->findIndex failed for index '.$i);
            $this->assertTrue($result->identicalTo($vars[$i]), 'result is identical to variable at index '.$i);
        }
    }

    public function testStringResize(): void
    {
        $ht = new HashTable();
        $vars = [];
        for ($i = 0; $i < HashTable::MIN_SIZE + 1; ++$i) {
            $vars[$i] = $var = $this->int($i + 1);
            $ht->add((string) $i, $var);
        }
        // resize triggers during MIN_SIZE + 1
        for ($i = 0; $i < HashTable::MIN_SIZE + 1; ++$i) {
            $result = $ht->find((string) $i);
            $this->assertNotNull($result, 'ht->findIndex failed for index '.$i);
            $this->assertTrue($result->identicalTo($vars[$i]), 'result is identical to variable at index '.$i);
        }
    }

    public function testFindOnUninitializedReturnsNull(): void
    {
        $ht = new HashTable();

        $this->assertNull($ht->find('missing'));
        $this->assertNull($ht->findIndex(0));
    }

    /** Regression: rehash must chain buckets (issue #248 POST / $_SERVER population). */
    public function testAddManyStringKeysIncludingContentLength(): void
    {
        $ht = new HashTable();
        $keys = [
            'REQUEST_METHOD' => 'POST',
            'QUERY_STRING' => '',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'REQUEST_URI' => '/index.php',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => 'PHP-Compiler-VM',
            'CONTENT_LENGTH' => '0',
        ];
        foreach ($keys as $name => $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $this->assertNotNull($ht->add($name, $var), 'add failed for '.$name);
        }
        foreach ($keys as $name => $value) {
            $found = $ht->find($name);
            $this->assertNotNull($found, 'find failed for '.$name);
            $this->assertSame($value, $found->resolveIndirect()->toString());
        }
    }

    /** Regression: rehash with holes after unset (#1761, #66). */
    public function testRehashAfterUnsetCreatesHoles(): void
    {
        $ht = new HashTable();
        for ($i = 0; $i < HashTable::MIN_SIZE + 2; ++$i) {
            $var = new Variable();
            $var->string('v'.$i);
            $ht->add('k'.$i, $var);
        }
        $removeKey = new Variable();
        $removeKey->string('k1');
        $ht->offsetUnset($removeKey);
        $this->assertFalse($ht->offsetIsSet($removeKey));
        $extra = new Variable();
        $extra->string('extra');
        $ht->add('k_extra', $extra);
        for ($i = 0; $i < HashTable::MIN_SIZE + 2; ++$i) {
            if (1 === $i) {
                continue;
            }
            $found = $ht->find('k'.$i);
            $this->assertNotNull($found, 'find failed for k'.$i.' after unset hole rehash');
            $this->assertSame('v'.$i, $found->resolveIndirect()->toString());
        }
        $this->assertSame('extra', $ht->find('k_extra')->resolveIndirect()->toString());
    }

    /** Regression: internal pointer advances after unset at current slot (#10349). */
    public function testPointerAdvancesAfterUnsetCurrent(): void
    {
        $ht = new HashTable();
        $x = new Variable();
        $x->int(1);
        $ht->add('x', $x);
        $y = new Variable();
        $y->int(2);
        $ht->add('y', $y);

        $removeX = new Variable();
        $removeX->string('x');
        $ht->offsetUnset($removeX);

        $key = $ht->pointerKey();
        $this->assertNotNull($key);
        $this->assertSame('y', $key->toString());
    }

    public function testPointerUnchangedAfterUnsetNonCurrent(): void
    {
        $ht = new HashTable();
        $x = new Variable();
        $x->int(1);
        $ht->add('x', $x);
        $y = new Variable();
        $y->int(2);
        $ht->add('y', $y);

        $removeY = new Variable();
        $removeY->string('y');
        $ht->offsetUnset($removeY);

        $key = $ht->pointerKey();
        $this->assertNotNull($key);
        $this->assertSame('x', $key->toString());
    }

    private function int(int $value): Variable
    {
        $var = new Variable();
        $var->int($value);

        return $var;
    }
}
