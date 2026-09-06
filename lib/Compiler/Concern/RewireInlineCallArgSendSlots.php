<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg SEND-slot rewires (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Hollow hub after Concern extracts (#36387). Remaining SEND rewires live in:
 * {@see RewireHoistedPreludePregCombineAndVarExportCallArgSendSlots}
 * (register_shutdown / preg_replace_callback / array_combine / nested var_export).
 * Arithmetic-branch / substr+sprintf / enum-prefix / sibling multi-arg / hoisted-prelude
 * helpers live in {@see RewireArithmeticBranchSubstrEnumAndSiblingMultiArgCallArgSendSlots}.
 * Bitmask / nested-file / var_export-flag peers (+ {@see slotForInlineExprResultInProducerOps})
 * live in {@see RewireInlineBitmaskNestedFileAndVarExportFlagCallArgSendSlots}.
 * Hoisted-sibling feed helpers + array_keys/combine rewire live in
 * {@see HoistedSiblingFeedAndArrayKeysArgSendRewire}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgSlotResolvers).
 */
trait RewireInlineCallArgSendSlots
{
}
