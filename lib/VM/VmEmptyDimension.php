<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\VmDomCollectionDimension;
use PHPCompiler\ext\simplexml\SimpleXmlRegistry;
use PHPCompiler\ext\simplexml\VmSimpleXml;
use PHPCompiler\ext\standard\boolval;
use PHPCompiler\Frame;

/**
 * empty($container[$dim]) — Zend zend_check_empty / zend_isset_dim parity (#14798).
 *
 * php-src: Zend/zend_operators.c — ArrayAccess uses offsetExists then value truthiness;
 * native arrays use key presence then value truthiness (not isset() semantics).
 */
final class VmEmptyDimension
{
    /**
     * @return ?Frame catch frame when user code handles a throw
     */
    public static function evaluate(
        \PHPCompiler\VM $vm,
        Variable $container,
        Variable $dim,
        Frame $frame,
        Variable $dst
    ): ?Frame {
        $container = $container->resolveIndirect();
        if (Variable::TYPE_ARRAY === $container->type) {
            if ($vm->context->isGlobalsTable($container)) {
                $dst->bool(self::emptyGlobalsOffset($vm, $dim));

                return null;
            }
            $table = $container->toArray();
            try {
                // Same illegal-offset wording as isset() / ZEND_ISSET_ISEMPTY_DIM_OBJ (#29549).
                // Float→int E_DEPRECATED once here; findVariable must not re-warn (#29560).
                if (!$table->keyExists(
                    $dim,
                    false,
                    $frame,
                    true,
                    'Illegal offset type in isset or empty'
                )) {
                    $dst->bool(true);

                    return null;
                }
                $stored = $table->findVariable($dim, false, $vm->context, $frame, false);
                $dst->bool(!boolval::isTruthy($stored->resolveIndirect()));
            } catch (\TypeError $e) {
                return $vm->propagateEmptyDimensionTypeError($e, $frame);
            }

            return null;
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            $object = $container->toObject();
            if (EnumCaseSupport::isEnumCase($object)) {
                throw new \TypeError('Illegal offset type in isset or empty');
            }
            if (VmDomCollectionDimension::isCollection($object)) {
                try {
                    if (VmDom::isTokenList($object)) {
                        // empty($tl[$i]) — has_dimension(check_empty) / zend_is_true (token_list.c; #23006).
                        $dst->bool(VmDomCollectionDimension::tokenListDimensionIsEmpty($object, $dim));

                        return null;
                    }
                    // empty($list[$i]) — has_dimension; nodes are never empty (php-src php_dom.c; #20311).
                    $dst->bool(!VmDomCollectionDimension::hasDimension($object, $dim));
                } catch (\TypeError $e) {
                    return $vm->propagateEmptyDimensionTypeError($e, $frame);
                }

                return null;
            }
            // SimpleXMLElement: empty($sxe[$dim]) uses string emptiness, not object truthiness (#25338).
            if (
                VmSimpleXml::CLASS_LC === strtolower($object->class->name)
                && SimpleXmlRegistry::has($object)
            ) {
                try {
                    $dst->bool(VmSimpleXml::dimensionIsEmpty($object, $dim));
                } catch (\TypeError $e) {
                    return $vm->propagateEmptyDimensionTypeError($e, $frame);
                }

                return null;
            }
            // Resource as array subject — empty soft-true like scalars (zend_execute.c, #30028).
            if (ResourceSupport::isResourceObject($object)) {
                $dst->bool(true);

                return null;
            }
            if (!$vm->objectImplementsArrayAccess($object)) {
                $className = $object->class->name;
                $catchFrame = $vm->propagateEmptyDimensionError(
                    'Cannot use object of type ' . $className . ' as array',
                    $frame
                );

                return $catchFrame;
            }
            $existsOut = new Variable();
            $catchFrame = $vm->invokeArrayAccessOffsetExists($object, $dim, $frame, $existsOut);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if (!$existsOut->toBool()) {
                $dst->bool(true);

                return null;
            }
            $valueOut = new Variable();
            $catchFrame = $vm->invokeArrayAccessOffsetGet($object, $dim, $frame, $valueOut);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $dst->bool(!boolval::isTruthy($valueOut->resolveIndirect()));

            return null;
        }
        if (Variable::TYPE_STRING === $container->type) {
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            if (!Variable::stringOffsetIsSetFromDim(
                $container,
                $dim,
                $vm->context->errors,
                $vm->context,
                $frame,
                $scriptFile
            )) {
                $dst->bool(true);

                return null;
            }
            try {
                // Index already coerced via isset path (#29557/#29558) — silent coerce for the char fetch.
                $rawIndex = Variable::stringOffsetIndexFromDim(
                    $dim,
                    null,
                    $vm->context,
                    $frame,
                    $scriptFile
                );
            } catch (\TypeError $e) {
                return $vm->propagateEmptyDimensionTypeError($e, $frame);
            }
            // Private Variable::$string is invisible here; ?? treated the read as null (#23071).
            $str = $container->toString();
            $index = $rawIndex;
            if ($index < 0) {
                $index += \strlen($str);
            }
            $char = $str[$index];
            $charVar = new Variable();
            $charVar->string($char);
            $dst->bool(!boolval::isTruthy($charVar));

            return null;
        }
        $dst->bool(true);

        return null;
    }

    private static function emptyGlobalsOffset(\PHPCompiler\VM $vm, Variable $dim): bool
    {
        return $vm->context->globalsTableOffsetIsEmpty($dim);
    }
}
