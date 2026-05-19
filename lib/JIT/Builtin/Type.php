<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;

class Type extends Builtin {

    public Type\String_ $string;
    public Type\HashTable $hashtable;
    protected array $fields;

    public function register(): void {
        $this->string = new Type\String_($this->context, $this->loadType);
        // $this->object = new Type\Object_($this->context, $this->loadType);
        $this->value = new Type\Value($this->context, $this->loadType);
        $this->hashtable = new Type\HashTable($this->context, $this->loadType);
        // $this->maskedarray = new Type\MaskedArray($this->context, $this->loadType);
        // $this->nativearray = new Type\NativeArray($this->context, $this->loadType);
        $this->string->register();
        // $this->object->register();
        $this->value->register();
        $fntypeGetenv = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnGetenv = $this->context->module->addFunction('__compiler_getenv', $fntypeGetenv);
        $this->context->registerFunction('__compiler_getenv', $fnGetenv);
        $fntypeNumberFormat = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('double'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnNumberFormat = $this->context->module->addFunction('__compiler_number_format', $fntypeNumberFormat);
        $this->context->registerFunction('__compiler_number_format', $fnNumberFormat);
        $fntypeStripTags = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnStripTags = $this->context->module->addFunction('__compiler_strip_tags', $fntypeStripTags);
        $this->context->registerFunction('__compiler_strip_tags', $fnStripTags);
        $i8p = $this->context->getTypeFromString('int8*');
        $i32 = $this->context->getTypeFromString('int32');
        $sizeT = $this->context->getTypeFromString('size_t');
        foreach (
            [
                'getenv' => [$i8p, false, $i8p],
                'putenv' => [$i32, false, $i8p],
                'strlen' => [$sizeT, false, $i8p],
            ] as $libcName => [$ret, $vararg, $param]
        ) {
            $ft = $this->context->context->functionType($ret, $vararg, $param);
            $fn = $this->context->module->addFunction($libcName, $ft);
            $this->context->registerFunction($libcName, $fn);
        }
        $this->hashtable->register();
        // $this->maskedarray->register();
        // $this->nativearray->register();
    }


}
