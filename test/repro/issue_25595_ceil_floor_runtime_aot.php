<?php
/**
 * Issue #25595 AOT smoke — runtime float return (Reflection is VM/JIT).
 * Named floor/ceil AOT miscompile is pre-existing (#23259); positional float here.
 */
echo gettype(ceil(1.2)), "\n";
echo gettype(floor(1.2)), "\n";
echo ceil(1.2), "\n";
echo floor(1.2), "\n";
