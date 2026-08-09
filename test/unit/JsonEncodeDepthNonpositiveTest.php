<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\json_encode;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/**
 * json_encode() depth≤0 — Zend false + JSON_ERROR_DEPTH, not ValueError (#29345).
 *
 * php-src: ext/json/json.c PHP_FUNCTION(json_encode) sets encoder.max_depth without
 * rejecting depth≤0 (unlike json_decode / json_validate).
 */
final class JsonEncodeDepthNonpositiveTest extends TestCase
{
    public function testDepthZeroOnEmptyArrayReturnsFalse(): void
    {
        $r = $this->runEncode([], 0, 0);
        $this->assertFalse($r);
        $this->assertSame(VmJson::ERROR_DEPTH, VmJson::lastError());
        $this->assertSame('Maximum stack depth exceeded', VmJson::lastErrorMsg());
    }

    public function testDepthNegativeOnEmptyArrayReturnsFalse(): void
    {
        $r = $this->runEncode([], 0, -1);
        $this->assertFalse($r);
        $this->assertSame(VmJson::ERROR_DEPTH, VmJson::lastError());
    }

    public function testDepthZeroScalarSucceeds(): void
    {
        $r = $this->runEncode(1, 0, 0);
        $this->assertSame('1', $r);
        $this->assertSame(0, VmJson::lastError());
    }

    public function testThrowOnErrorPreservesStickyLastError(): void
    {
        VmJson::setLastError(0);
        $runtime = new Runtime();
        $fn = new json_encode();
        $frame = $fn->getFrame($runtime->vmContext);
        $value = new VMVariable();
        $value->array(new \PHPCompiler\VM\HashTable());
        $flags = new VMVariable();
        $flags->int(\JSON_THROW_ON_ERROR);
        $depth = new VMVariable();
        $depth->int(0);
        $frame->calledArgs = [$value, $flags, $depth];
        $frame->returnVar = new VMVariable();
        try {
            $fn->execute($frame);
            $this->fail('expected JsonException');
        } catch (\JsonException $e) {
            $this->assertSame(VmJson::ERROR_DEPTH, $e->getCode());
            $this->assertSame('Maximum stack depth exceeded', $e->getMessage());
            $this->assertSame(0, VmJson::lastError());
        }
    }

    /** @return string|false */
    private function runEncode(mixed $phpValue, int $flags, int $depth): string|false
    {
        $runtime = new Runtime();
        $fn = new json_encode();
        $frame = $fn->getFrame($runtime->vmContext);
        $value = new VMVariable();
        if (null === $phpValue) {
            $value->null();
        } elseif (\is_int($phpValue)) {
            $value->int($phpValue);
        } elseif (\is_array($phpValue)) {
            $ht = new \PHPCompiler\VM\HashTable();
            foreach ($phpValue as $k => $v) {
                $slot = new VMVariable();
                if (\is_int($v)) {
                    $slot->int($v);
                } else {
                    $slot->null();
                }
                if (\is_int($k)) {
                    $ht->addIndex($k, $slot);
                } else {
                    $ht->add((string) $k, $slot);
                }
            }
            $value->array($ht);
        } else {
            throw new \InvalidArgumentException('unsupported fixture type');
        }
        $flagsVar = new VMVariable();
        $flagsVar->int($flags);
        $depthVar = new VMVariable();
        $depthVar->int($depth);
        $frame->calledArgs = [$value, $flagsVar, $depthVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ret = $frame->returnVar->resolveIndirect();
        if (VMVariable::TYPE_BOOLEAN === $ret->type) {
            return $ret->toBool() ? 'true' : false;
        }
        if (VMVariable::TYPE_STRING === $ret->type) {
            return $ret->toString();
        }

        throw new \LogicException('unexpected return type '.$ret->type);
    }
}
