<?php
/**
 * AOT probe: LimitIterator null offset/limit soft-null (#31621).
 * No set_error_handler (AOT #1379). Soft-null DEPs on stderr; OOB on stdout.
 *
 * catch (OutOfBoundsException): AOT instanceof Throwable for Exception subclasses is
 * still broken on master; the specific OOB type matches Zend for this rewind path.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $li = new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo 'ok:' . json_encode(iterator_to_array($li)) . "\n";
} catch (OutOfBoundsException $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
