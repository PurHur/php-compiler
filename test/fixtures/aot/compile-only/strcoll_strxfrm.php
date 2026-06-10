<?php
// Compile-only (#4376): strcoll() and strxfrm() JIT/AOT lowering via libc.
echo strcoll('a', 'b'), "\n";
echo strxfrm('hello'), "\n";
