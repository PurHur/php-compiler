<?php

declare(strict_types=1);

$docRoot = get_cfg_var('doc_root');
if (false === $docRoot) {
    fwrite(STDERR, "fail: get_cfg_var(doc_root) returned false\n");
    exit(1);
}
if (!is_string($docRoot) || '' !== $docRoot) {
    fwrite(STDERR, "fail: get_cfg_var(doc_root) expected empty string, got ".var_export($docRoot, true)."\n");
    exit(1);
}
if (get_cfg_var('bogus_cfg_xyz_12543') !== false) {
    fwrite(STDERR, "fail: unknown cfg name should return false\n");
    exit(1);
}

echo "ok: doc_root=''\n";
