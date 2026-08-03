<?php
/**
 * Repro #26906 — AOT strtok() round-trip must not segfault.
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 ./phpc build -o /tmp/tok test/repro/issue_26906_aot_strtok.php
 *   /tmp/tok
 */
echo strtok('a.b.c', '.');
echo ',';
echo strtok('.');
