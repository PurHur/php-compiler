<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT;
use PHPCompiler\ModuleAbstract;

class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        return [
            $this->parseAndCompileFunction('str_repeat', __DIR__.'/str_repeat.php'),
            new abs(),
            new ceil(),
            new floor(),
            new round(),
            new sqrt(),
            new intval(),
            new floatval(),
            new boolval(),
            new gettype(),
            new int_min(),
            new int_max(),
            new intdiv(),
            new ord(),
            new chr(),
            new strcmp(),
        ];
    }

    public function jitInit(JIT\Context $context): void
    {
        try {
            $context->lookupFunction('strcmp');
        } catch (\Throwable $e) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('strcmp', $ft);
            $context->registerFunction('strcmp', $fn);
        }
        $double = $context->getTypeFromString('double');
        foreach (['ceil', 'floor', 'round', 'sqrt'] as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable $e) {
                $ft = $context->context->functionType($double, false, $double);
                $fn = $context->module->addFunction($name, $ft);
                $context->registerFunction($name, $fn);
            }
        }
    }
}
