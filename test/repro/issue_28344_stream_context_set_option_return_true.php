<?php
/**
 * #28344 — stream_context_set_option Reflection return true under PROFILE≥8.4.
 */
$r = new ReflectionFunction('stream_context_set_option');
echo 'set_option => ', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
if (function_exists('stream_context_set_options')) {
    $r2 = new ReflectionFunction('stream_context_set_options');
    echo 'set_options => ', $r2->hasReturnType() ? (string) $r2->getReturnType() : 'NONE', "\n";
}
