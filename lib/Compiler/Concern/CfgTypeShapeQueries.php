<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;

/**
 * CFG type-shape query helpers (`cfgType*`) (#36387 / #36403).
 *
 * Extracted from {@see CfgTypeShapeAndDeclaredAssert} and
 * {@see CfgDeclaredTypeAssertAndParamApply} so gen-0 split-TU can hollow a
 * smaller Concern TU. Assert / apply / display helpers stay in those traits.
 * Mirrors php-src Zend/zend_compile.c type-hint tree walks — move-only, no new C ABI.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types (same as
 * InvokableReceiverAndClosureDetect / FirstClassCallableAndClosure).
 */
trait CfgTypeShapeQueries
{
    protected function cfgTypeUsesDnfShape(?Op\Type $declared): bool
    {
        if (null === $declared) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }
        if ($declared instanceof Op\Type\Nullable) {
            return true;
        }

        return false;
    }

    protected function cfgTypeIsStandaloneNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }

        return $type instanceof Op\Type\Literal && 'never' === strtolower($type->name);
    }

    /**
     * Zend void type node or literal — standalone only (returns / not properties).
     */
    protected function cfgTypeIsStandaloneVoid(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Void_) {
            return true;
        }

        return $type instanceof Op\Type\Literal && 'void' === strtolower($type->name);
    }

    protected function cfgTypeContainsNever(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Never_) {
            return true;
        }
        if ($type instanceof Op\Type\Literal && 'never' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNever($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNever($type->subtype);
        }

        return false;
    }

    /**
     * True when void appears anywhere in a declared type tree (union / nullable / intersection).
     */
    protected function cfgTypeContainsVoid(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Void_) {
            return true;
        }
        if ($type instanceof Op\Type\Literal && 'void' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsVoid($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsVoid($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsVoid($type->subtype);
        }

        return false;
    }

    /**
     * True when `never` appears inside an intersection (not a top-level union arm only).
     */
    protected function cfgTypeContainsNeverInIntersection(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsStandaloneNever($member)) {
                    return true;
                }
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNeverInIntersection($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNeverInIntersection($type->subtype);
        }

        return false;
    }

    protected function cfgTypeIsNullLiteral(?Op\Type $type): bool
    {
        return $type instanceof Op\Type\Literal && 'null' === strtolower($type->name);
    }

    protected function cfgTypeIsLiteralBoolName(?Op\Type $type, string $name): bool
    {
        return $type instanceof Op\Type\Literal && $name === strtolower($type->name);
    }

    protected function cfgTypeContainsLiteralBool(?Op\Type $type, string $name): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsLiteralBoolName($type, $name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsLiteralBool($member, $name)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsLiteralBool($type->subtype, $name);
        }

        return false;
    }

    protected function cfgTypeContainsNull(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsNullLiteral($type)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsNull($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsNull($type->subtype);
        }

        return false;
    }
    protected function cfgTypeIsPureMixed(?Op\Type $type): bool
    {
        if ($type instanceof Op\Type\Literal && 'mixed' === strtolower($type->name)) {
            return true;
        }

        return $type instanceof Op\Type\Mixed_;
    }

    protected function cfgTypeIsNullableMixed(?Op\Type $type): bool
    {
        return $type instanceof Op\Type\Nullable && $this->cfgTypeIsPureMixed($type->subtype);
    }

    /**
     * True when user-written {@code mixed} appears in a union/intersection (not as a lone type).
     * Nullable-of-pure-mixed is handled by {@see cfgTypeIsNullableMixed} instead.
     */
    protected function cfgTypeContainsNonStandaloneMixed(?Op\Type $type): bool
    {
        if (null === $type || $this->cfgTypeIsPureMixed($type)) {
            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            if ($this->cfgTypeIsPureMixed($type->subtype)) {
                return false;
            }

            return $this->cfgTypeContainsNonStandaloneMixed($type->subtype);
        }
        if ($type instanceof Op\Type\Union_) {
            $hasMixed = false;
            $hasOther = false;
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsPureMixed($member) || $this->cfgTypeContainsPureMixed($member)) {
                    $hasMixed = true;
                } else {
                    $hasOther = true;
                }
                if ($this->cfgTypeContainsNonStandaloneMixed($member)) {
                    return true;
                }
            }

            return $hasMixed && $hasOther;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeIsPureMixed($member) || $this->cfgTypeContainsPureMixed($member)) {
                    return true;
                }
                if ($this->cfgTypeContainsNonStandaloneMixed($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function cfgTypeContainsPureMixed(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($this->cfgTypeIsPureMixed($type)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_ || $type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsPureMixed($member)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsPureMixed($type->subtype);
        }

        return false;
    }

    /**
     * True when callable appears anywhere in a declared type tree (union / nullable / intersection).
     */
    protected function cfgTypeContainsCallable(?Op\Type $type): bool
    {
        if (null === $type) {
            return false;
        }
        if ($type instanceof Op\Type\Literal && 'callable' === strtolower($type->name)) {
            return true;
        }
        if ($type instanceof Op\Type\Union_) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsCallable($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach ($type->types as $member) {
                if ($this->cfgTypeContainsCallable($member)) {
                    return true;
                }
            }
        }
        if ($type instanceof Op\Type\Nullable) {
            return $this->cfgTypeContainsCallable($type->subtype);
        }

        return false;
    }

}
