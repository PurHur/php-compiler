<?php
// Repro #28707 — AOT SplObjectStorage getInfo + foreach offsetGet must match VM.
$s = new SplObjectStorage();
$a = new stdClass();
$b = new stdClass();
$s[$a] = 10;
$s[$b] = 20;
echo 'direct=', var_export($s[$a], true), "\n";
$s->rewind();
echo 'info=', var_export($s->getInfo(), true), "\n";
foreach ($s as $o) {
    echo 'v=', var_export($s[$o], true), "\n";
}
