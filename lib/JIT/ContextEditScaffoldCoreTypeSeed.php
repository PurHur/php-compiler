<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * Edit-scaffold core-type / structFieldMap seed from restored bitcode (#36387 / #36199).
 *
 * Extracted from {@see ContextEditScaffoldModuleRebind} so typeMap + structFieldMap
 * population (including CreateNamed uniquified aliases) stays a separate TU from
 * init/shutdown rebind and function-scope refresh (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextEditScaffoldCoreTypeSeed;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: Zend attaches a cached script image and rebinds
 * type / class tables without a full re-compile (Zend/zend_file_cache.c,
 * Zend/zend_types.h layout maps).
 */
trait ContextEditScaffoldCoreTypeSeed
{
    /**
     * Populate typeMap + structFieldMap from restored bitcode so register() can early-return (#36387).
     *
     * Prepared for edit-scaffold thin boot (parse module.bc before namedStructType).
     */
    public function seedCoreTypesFromModuleForEditScaffold(): void
    {
        $cores = [
            '__ref__' => ['refcount' => 0, 'typeinfo' => 1],
            '__ref__virtual' => ['ref' => 0],
            '__string__' => ['ref' => 0, 'length' => 1, 'value' => 2],
            '__value__' => ['type' => 0, 'pad' => 1, 'value' => 2],
            '__value__value' => ['ref' => 0],
            '__hashtable__' => [
                'ref' => 0,
                'numElements' => 1,
                'nextFreeElement' => 2,
                'capacity' => 3,
                'values' => 4,
                'strKeys' => 5,
                'objKeys' => 6,
                'internalPointer' => 7,
                'packedPrefixEnd' => 8,
                'strKeysTail' => 9,
                'strHashMask' => 10,
                'strHashSlots' => 11,
            ],
            '__strkey_node__' => [
                'ref' => 0,
                'key' => 1,
                'value' => 2,
                'next' => 3,
                'hash' => 4,
                'hashNext' => 5,
            ],
            '__objkey_node__' => [
                'ref' => 0,
                'key' => 1,
                'value' => 2,
                'next' => 3,
            ],
            '__object__' => [
                'ref' => 0,
                'class_id' => 1,
                'constructed' => 2,
                'lazy_pending' => 3,
                'lazy_ghost' => 4,
                'lazy_init_index' => 5,
                'dynamic_readonly' => 6,
                'prop_count' => 7,
                'user_handle' => 8,
            ],
        ];
        foreach ($cores as $name => $fields) {
            $ty = null;
            try {
                $ty = $this->module->getTypeByName($name);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$ty instanceof PHPLLVM\Type) {
                continue;
            }
            try {
                $ty->getKind();
            } catch (\Throwable $e) {
                continue;
            }
            $this->typeMap[$name] = $ty;
            $this->typeMap[$name.'*'] = $ty->pointerType(0);
            $this->typeMap[$name.'**'] = $ty->pointerType(0)->pointerType(0);
            if (is_array($fields)) {
                $this->structFieldMap[$name] = $fields;
            }
        }
        // Alias CreateNamed uniquified siblings (__string__.2) to the same field map (#36387).
        foreach (array_keys($cores) as $name) {
            if (!isset($this->structFieldMap[$name])) {
                continue;
            }
            for ($i = 1; $i <= 32; ++$i) {
                $alias = $name.'.'.$i;
                try {
                    $aliasTy = $this->module->getTypeByName($alias);
                } catch (\Throwable $e) {
                    continue;
                }
                if (!$aliasTy instanceof PHPLLVM\Type) {
                    continue;
                }
                try {
                    $aliasTy->getKind();
                } catch (\Throwable $e) {
                    continue;
                }
                $this->typeMap[$alias] = $aliasTy;
                $this->typeMap[$alias.'*'] = $aliasTy->pointerType(0);
                $this->typeMap[$alias.'**'] = $aliasTy->pointerType(0)->pointerType(0);
                $this->structFieldMap[$alias] = $this->structFieldMap[$name];
            }
        }
    }
}
