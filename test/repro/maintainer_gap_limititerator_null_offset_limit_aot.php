<?php
/**
 * AOT probe: LimitIterator null offset/limit soft-null (#31621).
 * No set_error_handler (AOT #1379). Soft-null DEPs on stderr; result/OOB on stdout.
 *
 * AOT LimitIterator snapshots the HT window — limit 0 yields empty iteration (no OOB),
 * unlike VM/JIT which throw on rewind/seek. Assert DEPs + empty ok.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $li = new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo 'ok:' . json_encode(iterator_to_array($li)) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
