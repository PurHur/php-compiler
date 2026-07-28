<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\unserialize;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * #24149 — unserialize() options typing matches Zend TypeError (ext/standard/var.c).
 */
final class UnserializeAllowedClassesTypeErrorTest extends TestCase
{
    public function testAllowedClassesStringThrowsTypeError(): void
    {
        $opts = $this->optionsArray(['allowed_classes' => $this->stringVar('nope')]);
        try {
            unserialize::parseUnserializeOptionsArray($opts);
            $this->fail('expected TypeError');
        } catch (\TypeError $e) {
            $this->assertSame(
                'unserialize(): Option "allowed_classes" must be of type array|bool, string given',
                $e->getMessage()
            );
        }
    }

    public function testAllowedClassesObjectThrowsTypeError(): void
    {
        $this->assertSame(
            'unserialize(): Option "allowed_classes" must be of type array|bool, stdClass given',
            unserialize::allowedClassesOptionTypeErrorMessageFromMixed(new \stdClass())
        );
    }

    public function testMaxDepthStringThrowsTypeError(): void
    {
        $opts = $this->optionsArray(['max_depth' => $this->stringVar('nope')]);
        try {
            unserialize::parseUnserializeOptionsArray($opts);
            $this->fail('expected TypeError');
        } catch (\TypeError $e) {
            $this->assertSame(
                'unserialize(): Option "max_depth" must be of type int, string given',
                $e->getMessage()
            );
        }
    }

    public function testAllowedClassesBoolAndArrayAccepted(): void
    {
        $trueOpts = $this->optionsArray(['allowed_classes' => $this->boolVar(true)]);
        $this->assertSame(['allowed_classes' => true], unserialize::parseUnserializeOptionsArray($trueOpts));

        $list = new Variable(Variable::TYPE_ARRAY);
        $ht = new HashTable();
        $ht->append($this->stringVar('stdClass'));
        $list->array($ht);
        $arrOpts = $this->optionsArray(['allowed_classes' => $list]);
        $this->assertSame(
            ['allowed_classes' => ['stdClass']],
            unserialize::parseUnserializeOptionsArray($arrOpts)
        );
    }

    /** @param array<string, Variable> $entries */
    private function optionsArray(array $entries): Variable
    {
        $ht = new HashTable();
        foreach ($entries as $key => $value) {
            $ht->add($key, $value);
        }
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return $var;
    }

    private function stringVar(string $s): Variable
    {
        $v = new Variable(Variable::TYPE_STRING);
        $v->string($s);

        return $v;
    }

    private function boolVar(bool $b): Variable
    {
        $v = new Variable(Variable::TYPE_BOOLEAN);
        $v->bool($b);

        return $v;
    }
}
