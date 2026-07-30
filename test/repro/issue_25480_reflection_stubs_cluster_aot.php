<?php
/**
 * Issue #25480 AOT smoke — restore_error_handler() return (Reflection is VM/JIT).
 * dirname/range AOT segfault and array_replace HashTable::replaceCopy casing are
 * pre-existing and out of scope for this Reflection stub fix.
 */
echo (int) restore_error_handler(), "\n";
