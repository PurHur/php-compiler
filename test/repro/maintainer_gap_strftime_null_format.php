<?php

// #18945 — strftime(null)/gmstrftime(null) return false (ext/standard/datetime.c).
if (false !== strftime(null)) {
    echo 'fail strftime: expected false, got ', var_export(strftime(null), true), "\n";
    exit(1);
}
if (false !== gmstrftime(null)) {
    echo 'fail gmstrftime: expected false, got ', var_export(gmstrftime(null), true), "\n";
    exit(1);
}

echo "ok\n";
