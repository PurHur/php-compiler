<?php declare(strict_types=1);

namespace PHPLLVM\LLVMAbstract;

use PHPLLVM\LLVM as CoreLLVM;
use PHPLLVM\BasicBlock as CoreBasicBlock;
use PHPLLVM\Builder as CoreBuilder;
use PHPLLVM\Context as CoreContext;
use PHPLLVM\Intrinsic as CoreIntrinsic;
use PHPLLVM\Module as CoreModule;
use PHPLLVM\Type as CoreType;
use PHPLLVM\Value as CoreValue;
use PHPLLVM\TargetData as CoreTargetData;
use PHPLLVM\TargetMachine as CoreTargetMachine;
use PHPLLVM\ExecutionEngine as CoreExecutionEngine;

use llvm\llvm as lib;

class Intrinsic implements CoreIntrinsic {
    public LLVM $llvm;
    public Context $context;
    public Module $module;
    public Builder $builder;

    public function __construct(LLVM $llvm, Context $context, Module $module, Builder $builder) {
        $this->llvm = $llvm;
        $this->context = $context;
        $this->module = $module;
        $this->builder = $builder;
    }


    public function va_start(CoreValue $arglist): void {
        $func = $this->module->getNamedFunction('llvm.va_start');
        if ($func === null) {
            $func = $this->module->addFunction(
                "llvm.va_start",
                $this->context->functionType(
                    $this->context->voidType(),
                    false,
                    $this->context->int8Type()->pointerType(0)
                )
            );
        }
        $this->builder->call($func, $arglist);
    }

    public function va_end(CoreValue $arglist): void {
        $func = $this->module->getNamedFunction('llvm.va_end');
        if ($func === null) {
            $func = $this->module->addFunction(
                "llvm.va_end",
                $this->context->functionType(
                    $this->context->voidType(),
                    false,
                    $this->context->int8Type()->pointerType(0)
                )
            );
        }
        $this->builder->call($func, $arglist);
    }

    public function va_copy(CoreValue $dest, CoreValue $src): void {
        $func = $this->module->getNamedFunction('llvm.va_copy');
        if ($func === null) {
            $func = $this->module->addFunction(
                "llvm.va_copy",
                $this->context->functionType(
                    $this->context->voidType(),
                    false,
                    $this->context->int8Type()->pointerType(0),
                    $this->context->int8Type()->pointerType(0)
                )
            );
        }
        $this->builder->call($func, $arglist);
    }

    /**
     * Emit libc memcpy — llvm.memcpy.* intrinsics lower to unresolved null
     * calls under MCJIT on php-compiler:22.04-dev (#98, #2055, #21109).
     */
    public function memcpy(CoreValue $dest, CoreValue $src, CoreValue $size, bool $isVolatile): void {
        unset($isVolatile);
        $i8p = $this->context->int8Type()->pointerType(0);
        $sizeTy = $size->typeOf();
        $func = $this->module->getNamedFunction('memcpy');
        if ($func === null) {
            $func = $this->module->addFunction(
                'memcpy',
                $this->context->functionType($i8p, false, $i8p, $i8p, $sizeTy)
            );
        }
        $this->builder->call($func, $dest, $src, $size);
    }

    /**
     * Emit libc memmove — see memcpy() (#98, #2055, #21109).
     */
    public function memmove(CoreValue $dest, CoreValue $src, CoreValue $size, bool $isVolatile): void {
        unset($isVolatile);
        $i8p = $this->context->int8Type()->pointerType(0);
        $sizeTy = $size->typeOf();
        $func = $this->module->getNamedFunction('memmove');
        if ($func === null) {
            $func = $this->module->addFunction(
                'memmove',
                $this->context->functionType($i8p, false, $i8p, $i8p, $sizeTy)
            );
        }
        $this->builder->call($func, $dest, $src, $size);
    }

    /**
     * Emit libc memset — see memcpy() (#98, #2055, #21109).
     *
     * @param CoreValue $src Fill byte (i8) — name kept for Intrinsic interface.
     */
    public function memset(CoreValue $dest, CoreValue $src, CoreValue $size, bool $isVolatile): void {
        unset($isVolatile);
        $i8p = $this->context->int8Type()->pointerType(0);
        $i32 = $this->context->int32Type();
        $sizeTy = $size->typeOf();
        $func = $this->module->getNamedFunction('memset');
        if ($func === null) {
            $func = $this->module->addFunction(
                'memset',
                $this->context->functionType($i8p, false, $i8p, $i32, $sizeTy)
            );
        }
        $fill = $src;
        if ($fill->typeOf()->getWidth() < 32) {
            $fill = $this->builder->intCast($fill, $i32);
        } elseif ($fill->typeOf()->getWidth() > 32) {
            $fill = $this->builder->truncOrBitCast($fill, $i32);
        }
        $this->builder->call($func, $dest, $fill, $size);
    }

    public function bitreverse(CoreValue $value): CoreValue {
        return $this->builder->call(
            $this->getIntFunction('llvm.bitreverse', $value->typeOf()),
            $value
        );
    }

    public function bitswap(CoreValue $value): CoreValue {
        return $this->builder->call(
            $this->getIntFunction('llvm.bswap', $value->typeOf()),
            $value
        );
    }

    public function ctpop(CoreValue $value): CoreValue {
        return $this->builder->call(
            $this->getIntFunction('llvm.ctpop', $value->typeOf()),
            $value
        );
    }

    public function ctlz(CoreValue $value, bool $isZeroUndef): CoreValue {
        $int1 = $this->context->int1Type();
        return $this->builder->call(
            $this->getIntFunction('llvm.ctlz', $value->typeOf(), $int1),
            $value,
            $int1->constInt($isZeroUndef ? 1 : 0, false)
        );
    }

    public function cttz(CoreValue $value, bool $isZeroUndef): CoreValue {
        $int1 = $this->context->int1Type();
        return $this->builder->call(
            $this->getIntFunction('llvm.cttz', $value->typeOf(), $int1),
            $value,
            $int1->constInt($isZeroUndef ? 1 : 0, false)
        );
    }

    protected function getIntFunction(string $name, Type $type, Type ... $extra): Value {
        $funcName = $name . '.i' . $type->getWidth();
        $func = $this->module->getNamedFunction($name);
        if ($func === null) {
            $func = $this->module->addFunction(
                $funcName,
                $this->context->functionType(
                    $type,
                    false,
                    $type,
                    ...$extra
                )
            );
        }
        return $func;
    }

}