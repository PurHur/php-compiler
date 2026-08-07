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

    /**
     * By-ref FE_FETCH must promote the bucket to a shared ref cell so residual aliases
     * survive HashTable::duplicate() (append / COW) — Zend FE_FETCH_RW (#26738).
     */
    public function testForeachByRefSurvivesDuplicateCow(): void
    {
        $ht = new HashTable();
        foreach ([10, 20] as $i => $n) {
            $v = new Variable();
            $v->int($n);
            $ht->addIndex($i, $v);
        }
        $ht->iterReset();
        $this->assertTrue($ht->iterValid());
        $this->assertTrue($ht->iterValid());
        $payload = $ht->iterCurrentValue(true);
        $loop = new Variable();
        $loop->indirect($payload);

        $copy = $ht->duplicate();
        $write = new Variable();
        $write->int(99);
        $loop->copyFrom($write);

        $this->assertSame(99, $ht->findIndex(1)->resolveIndirect()->toInt());
        $this->assertSame(99, $copy->findIndex(1)->resolveIndirect()->toInt());
        $this->assertTrue($ht->findIndex(1)->isIndirect());
        $this->assertTrue($copy->findIndex(1)->isIndirect());
    }

    /**
     * Foreach cursor (Z_FE_POS) must not skip the next element when unset deletes the
     * current bucket — nInternalPointer update must not feed ITER_VALID's ++ (#21985).
     */
    public function testForeachCursorContinuesAfterUnsetCurrent(): void
    {
        $ht = new HashTable();
        foreach ([1, 2, 3] as $i => $n) {
            $v = new Variable();
            $v->int($n);
            $ht->addIndex($i, $v);
        }
        $ht->iterReset();
        $this->assertTrue($ht->iterValid());
        $this->assertSame(0, $ht->iterCurrentKey()->toInt());
        $this->assertTrue($ht->iterValid());
        $this->assertSame(1, $ht->iterCurrentKey()->toInt());

        $remove = new Variable();
        $remove->int(1);
        $ht->offsetUnset($remove);

        $this->assertTrue($ht->iterValid(), 'foreach must visit key 2 after unset of current');
        $this->assertSame(2, $ht->iterCurrentKey()->toInt());
        $this->assertSame(3, $ht->iterCurrentValue(false)->toInt());
        $this->assertFalse($ht->iterValid());
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

    /**
     * Issue #9534 / #28762 — PHP_INT_MAX index wraps nNextFreeElement (zend_long);
     * `$a[]` then throws Zend's "next element is already occupied" Error.
     */
    public function testAddIndexAtPhpIntMaxMakesAppendThrowOccupied(): void
    {
        $ht = new HashTable();
        $value = $this->int(1);
        $ht->addIndex(\PHP_INT_MAX, $value);

        $found = $ht->findIndex(\PHP_INT_MAX);
        $this->assertNotNull($found);
        $this->assertTrue($found->identicalTo($value));

        $appended = new Variable();
        $appended->string('tail');
        $this->expectException(\Error::class);
        $this->expectExceptionMessage(HashTable::NEXT_ELEMENT_OCCUPIED_MESSAGE);
        $ht->append($appended);
    }

    /** Control: append after PHP_INT_MAX-1 still claims PHP_INT_MAX (#28762). */
    public function testAppendAfterPhpIntMaxMinusOneUsesPhpIntMax(): void
    {
        $ht = new HashTable();
        $ht->addIndex(\PHP_INT_MAX - 1, $this->int(1));
        $tail = new Variable();
        $tail->string('tail');
        $ht->append($tail);
        $atMax = $ht->findIndex(\PHP_INT_MAX);
        $this->assertNotNull($atMax);
        $this->assertSame('tail', $atMax->toString());
    }

    /** Issue #23485 — array === requires identical element types (no == juggling). */
    public function testCompareIdenticalRejectsJuggledElementTypes(): void
    {
        $left = new HashTable();
        $left->append($this->int(1));
        $left->append($this->int(2));

        $right = new HashTable();
        $right->append($this->int(1));
        $strTwo = new Variable();
        $strTwo->string('2');
        $right->append($strTwo);

        $this->assertTrue($left->compareLooseEqual($right));
        $this->assertFalse($left->compareIdentical($right));

        $leftVar = new Variable();
        $leftVar->array($left);
        $rightVar = new Variable();
        $rightVar->array($right);
        $this->assertTrue($leftVar->equals($rightVar));
        $this->assertFalse($leftVar->identicalTo($rightVar));

        $same = new HashTable();
        $same->append($this->int(1));
        $same->append($this->int(2));
        $this->assertTrue($left->compareIdentical($same));
    }

    /**
     * Issue #23985 / #23988 — == and <=> compare key bags; === stays order-sensitive
     * (Zend zend_hash_compare ordered=false vs ordered=true).
     */
    public function testCompareLooseEqualAndSpaceshipIgnoreKeyOrder(): void
    {
        $left = new HashTable();
        $left->addIndex(0, $this->int(1));
        $left->addIndex(1, $this->int(2));

        $right = new HashTable();
        $right->addIndex(1, $this->int(2));
        $right->addIndex(0, $this->int(1));

        $this->assertTrue($left->compareLooseEqual($right));
        $this->assertSame(0, $left->compareSpaceship($right));
        $this->assertFalse($left->compareIdentical($right));

        $assocLeft = new HashTable();
        $assocLeft->add('a', $this->int(1));
        $assocLeft->add('b', $this->int(2));
        $assocRight = new HashTable();
        $assocRight->add('b', $this->int(2));
        $assocRight->add('a', $this->int(1));
        $this->assertTrue($assocLeft->compareLooseEqual($assocRight));
        $this->assertSame(0, $assocLeft->compareSpaceship($assocRight));
        $this->assertFalse($assocLeft->compareIdentical($assocRight));

        $smaller = new HashTable();
        $smaller->addIndex(0, $this->int(1));
        $this->assertSame(-1, $smaller->compareSpaceship($left));
        $this->assertSame(1, $left->compareSpaceship($smaller));
    }

    private function int(int $value): Variable
    {
        $var = new Variable();
        $var->int($value);

        return $var;
    }
}
