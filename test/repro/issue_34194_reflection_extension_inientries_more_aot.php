<?php
/**
 * AOT: ReflectionExtension getINIEntries for zlib/iconv (#34194 leftover after #34193).
 */
foreach (['zlib', 'iconv'] as $e) {
    $c = (new ReflectionExtension($e))->getINIEntries();
    echo $e, ' count=', count($c), "\n";
}
// Peers from #34188 / #34193 stay green
foreach (['openssl', 'filter', 'standard', 'date'] as $e) {
    $c = (new ReflectionExtension($e))->getINIEntries();
    echo $e, ' count=', count($c), "\n";
}
