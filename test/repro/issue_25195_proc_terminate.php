<?php

declare(strict_types=1);

// Repro #25195: empty descriptor_spec + proc_terminate must kill the child (Zend parity).
// sleep keeps the child alive long enough for terminate to be observable.
$result = proc_open('sleep 30', [], $pipes);
echo 'is_resource=', (int) is_resource($result), "\n";
if (!is_resource($result)) {
    exit(1);
}

$st = proc_get_status($result);
echo 'running_before=', (int) ($st['running'] ?? 0), "\n";

$ok = proc_terminate($result);
echo 'terminate=', (int) $ok, "\n";
usleep(200000);

$st2 = proc_get_status($result);
echo 'running_after=', (int) ($st2['running'] ?? -1), "\n";
echo 'signaled=', (int) ($st2['signaled'] ?? -1), "\n";
echo 'termsig=', (int) ($st2['termsig'] ?? -1), "\n";
@proc_close($result);
echo "done\n";
