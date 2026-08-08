<?php
/**
 * AOT probe for #28854 — named move_uploaded_file(from:/to:).
 *
 * Note: thin AOT for move_uploaded_file() itself is red on master for positional
 * calls too ("Current basic block has no parent function" — JitUploadTempKernel /
 * peer #26884). This file documents the named-arg surface; do not treat a failed
 * AOT link as a regression of the Reflection/BuiltinParamNames fix.
 */
$ok = move_uploaded_file(from: '/nope-from', to: '/nope-to');
echo 'named_from_to=', ($ok === false ? 'false' : 'true'), "\n";
$pos = move_uploaded_file('/nope-from', '/nope-to');
echo 'pos=', ($pos === false ? 'false' : 'true'), "\n";
