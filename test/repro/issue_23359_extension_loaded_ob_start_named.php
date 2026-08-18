<?php
/**
 * Repro #23359 — extension_loaded($extension) / ob_start($callback, ...) Zend stub names.
 * php-src: ext/standard/basic_functions.stub.php
 */
$elNames = [];
foreach ((new ReflectionFunction('extension_loaded'))->getParameters() as $p) {
    $elNames[] = $p->getName();
}
$obNames = [];
$obTypes = [];
foreach ((new ReflectionFunction('ob_start'))->getParameters() as $p) {
    $obNames[] = $p->getName();
    $obTypes[] = $p->hasType() ? (string) $p->getType() : 'NONE';
}
$loaded = extension_loaded(extension: 'standard');
$legacyEl = 'uncaught';
try {
    extension_loaded(extension_name: 'standard');
    $legacyEl = 'accepted';
} catch (Throwable $e) {
    $legacyEl = $e->getMessage();
}
$started = ob_start(callback: null);
echo 'inbuf';
$buf = ob_get_clean();
$legacyOb = 'uncaught';
try {
    ob_start(user_function: null);
    $legacyOb = 'accepted';
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
} catch (Throwable $e) {
    $legacyOb = $e->getMessage();
}
$ok = ['extension'] === $elNames
    && ['callback', 'chunk_size', 'flags'] === $obNames
    && isset($obTypes[0]) && '?callable' === $obTypes[0]
    && true === $loaded
    && true === $started
    && 'inbuf' === $buf
    && str_contains($legacyEl, 'Unknown named parameter $extension_name')
    && str_contains($legacyOb, 'Unknown named parameter $user_function');
echo $ok ? "ok\n" : ('fail names_el='.implode(',', $elNames)
    .' names_ob='.implode(',', $obNames)
    .' types_ob='.implode(',', $obTypes)
    .' loaded='.var_export($loaded, true)
    .' started='.var_export($started, true)
    .' buf='.var_export($buf, true)
    .' legacy_el='.$legacyEl
    .' legacy_ob='.$legacyOb."\n");
