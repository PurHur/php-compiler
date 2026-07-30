<?php
/**
 * Issue #25384 — inheritance LSP must reject incompatible overrides across require/eval.
 *
 * Same-script cases are already covered by InheritanceVariance + compliance .phpt;
 * this repro is the cross-file gap (parent and child in separate compile units).
 *
 * Expect Zend and VM: E_COMPILE_ERROR / exit 255, no "ret_accepted".
 */
require __DIR__.'/issue_inheritance_variance_lsp_parent.php';
require __DIR__.'/issue_inheritance_variance_lsp_child.php';
echo "ret_accepted\n";
