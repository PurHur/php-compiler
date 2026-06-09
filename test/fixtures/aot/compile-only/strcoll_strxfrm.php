<?php
// Compile-only (#4376): strcoll() JIT lowering; strxfrm() VM-only registration for AOT parse.
echo strcoll('a', 'b'), "\n";
echo strxfrm('hello'), "\n";
