<?php
/**
 * Repro #27290 — AOT htmlspecialchars encoding + double_encode.
 * Expect: &lt;&gt;&amp;&#039;&quot;
 */
echo htmlspecialchars("<>&'\"", ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8", false), "\n";
