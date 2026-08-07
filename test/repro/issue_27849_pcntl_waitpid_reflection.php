<?php
declare(strict_types=1);

$r = new ReflectionFunction('pcntl_waitpid');
echo 'return=', ($r->getReturnType() ? (string) $r->getReturnType() : 'none'), "\n";
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() : 'none';
    echo $p->getName(), ' type=', $t, ' byRef=', ($p->isPassedByReference() ? 'Y' : 'N'),
        ' opt=', ($p->isOptional() ? 'Y' : 'N'), "\n";
}
$st = 0;
$rc = pcntl_waitpid(process_id: -1, status: $st, flags: defined('WNOHANG') ? WNOHANG : 1);
echo 'named_ok=', var_export($rc, true), "\n";
