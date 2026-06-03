--TEST--
Language: JIT echo array literal — prints Array (#4964, zend_compile.c)
--FILE--
<?php
// MCJIT embed requires user-class init in module; inert bootstrap until #98 bare-literal execute.
class EchoArrayJitBootstrap {
    public function __toString(): string { return ''; }
}
echo [1, 2];
--EXPECT--
Array
