<?php
/**
 * Issue #30810 — AOT similar_text &$percent must not segfault (Zend/VM/JIT match).
 */
similar_text('programming', 'programmer', $p);
echo $p, "\n";
