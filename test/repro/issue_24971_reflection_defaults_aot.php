<?php
/**
 * #24971 AOT — omit-arg runtime for Reflection-default builtins (named Reflection under AOT is a pre-existing gap).
 */
echo dirname('/a/b/c'), "
";
echo basename('/a/b/c.txt', '.txt'), "
";
echo http_build_query(['x' => 1]), "
";
echo chunk_split('ab', 1, '|'), "
";
echo (int) version_compare('2.0', '1.0', '>'), "
";
