<?php declare(strict_types=1);

namespace PHPLLVM\LLVMAbstract;

use PHPLLVM\Context as CoreContext;
use PHPLLVM\MemoryBuffer as CoreMemoryBuffer;

use FFI;
use llvm\llvm as lib;
use llvm\LLVMMemoryBufferRef;
use llvm\LLVMModuleRef;
use llvm\string_ptr;

class MemoryBuffer implements CoreMemoryBuffer {

    public LLVM $llvm;
    public LLVMMemoryBufferRef $buffer;

    public function __construct(LLVM $llvm, LLVMMemoryBufferRef $buffer) {
        $this->llvm = $llvm;
        $this->buffer = $buffer;
    }

    public function getStart(): string {
        return $this->llvm->lib->LLVMGetBufferStart($this->buffer)->toString();
    }

    public function getSize(): int {
        return $this->llvm->lib->LLVMGetBufferSisze($this->buffer);
    }

    public function dispose(): void {
        $this->llvm->lib->LLVMDisposeMemoryBuffer($this->buffer);
    }

    public function parseBitcode(CoreContext $context): Module {
        $error = new string_ptr(FFI::addr(FFI::new('char*')));
        $module = new LLVMModuleRef($this->llvm->lib->getFFI()->new('LLVMModuleRef'));
        if (!$this->llvm->fromBool($this->llvm->lib->LLVMParseBitcodeInContext($context->context, $this->buffer, $module->addr(), $error))) {
            $message = $error->deref(0)->toString();
            $this->llvm->disposeMessage($error->deref(0));
            throw new \RuntimeException("Bitcode parsing failed due to $message");
        }
        return $this->llvm->factory->module($context, $module, 'preg_match_runtime');
    }

    public function getBitcodeModule(CoreContext $context): Module  {
        $error = new string_ptr(FFI::addr(FFI::new('char*')));
        $module = new LLVMModuleRef($this->llvm->lib->getFFI()->new('LLVMModuleRef'));
        if (!$this->llvm->fromBool($this->llvm->lib->LLVMGetBitcodeModuleInContext($context->context, $this->buffer, $module->addr(), $error))) {
            $message = $error->deref(0)->toString();
            $this->llvm->disposeMessage($error->deref(0));
            throw new \RuntimeException("Bitcode module fetch failed due to $message");
        }
        return $this->llvm->factory->module($context, $module, 'preg_match_runtime');
    }
}
