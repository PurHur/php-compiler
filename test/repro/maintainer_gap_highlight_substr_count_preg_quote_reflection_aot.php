<?php
/**
 * Issue #25472 AOT smoke — substr_count + highlight_string runtime (Reflection is VM/JIT).
 * preg_quote() AOT segfaults on master (pre-existing; out of scope for this Reflection stub fix).
 */
echo substr_count('aaa', 'a'), "\n";
echo (int) highlight_string('<?php echo 1;', false), "\n";
