<?php declare(strict_types=1);

namespace PHPLLVM\LLVM7;

use PHPLLVM\LLVMAbstract\Intrinsic as AbstractIntrinsic;

/**
 * LLVM 7+ Intrinsic — mem* helpers use libc via {@see AbstractIntrinsic}
 * so MCJIT can resolve symbols (#98, #2055, #21109). Prior overrides emitted
 * llvm.memset/memcpy declares that MCJIT left as call-through-null.
 */
class Intrinsic extends AbstractIntrinsic {
}
