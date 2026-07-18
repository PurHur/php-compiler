<?php
// repro #20565 — SQLite3 open/lastError/version/createCollation/backup
foreach (['open', 'lastErrorCode', 'lastErrorMsg', 'version', 'createCollation', 'backup'] as $m) {
    if (!method_exists(SQLite3::class, $m)) {
        fwrite(STDERR, "missing $m\n");
        exit(1);
    }
}
$v = SQLite3::version();
if (!isset($v['versionString'], $v['versionNumber'])) {
    fwrite(STDERR, "bad version\n");
    exit(1);
}
$db = new SQLite3(':memory:');
@$db->exec('SELECT no_such');
if ($db->lastErrorCode() <= 0) {
    fwrite(STDERR, "expected error code\n");
    exit(1);
}
$db->close();
$db->open(':memory:');
$db->exec('CREATE TABLE t(x); INSERT INTO t VALUES (1);');
$dst = new SQLite3(':memory:');
if (!$db->backup($dst)) {
    fwrite(STDERR, "backup failed\n");
    exit(1);
}
if (1 != $dst->querySingle('SELECT x FROM t')) {
    fwrite(STDERR, "backup data mismatch\n");
    exit(1);
}
echo "ok\n";
