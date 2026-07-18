<?php
// AOT advertisement probe #20565 — method_exists for new SQLite3 APIs
// (instance method *execution* under AOT remains a pre-existing SQLite3 gap, same as exec).
echo method_exists(SQLite3::class, 'open') ? "open=Y\n" : "open=N\n";
echo method_exists(SQLite3::class, 'lastErrorCode') ? "err=Y\n" : "err=N\n";
echo method_exists(SQLite3::class, 'lastErrorMsg') ? "msg=Y\n" : "msg=N\n";
echo method_exists(SQLite3::class, 'version') ? "ver=Y\n" : "ver=N\n";
echo method_exists(SQLite3::class, 'createCollation') ? "col=Y\n" : "col=N\n";
echo method_exists(SQLite3::class, 'backup') ? "bak=Y\n" : "bak=N\n";
echo "ok\n";
