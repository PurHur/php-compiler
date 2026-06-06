<?php
enum E: string { case A = 'x'; }
$tests = ['strcmp', 'strncmp', 'strcasecmp', 'strncasecmp', 'levenshtein'];
foreach ($tests as $fn) {
    try {
        if ('strncmp' === $fn || 'strncasecmp' === $fn) {
            $fn(E::A, 'x', 1);
        } elseif ('levenshtein' === $fn) {
            $fn(E::A, 'x');
        } else {
            $fn(E::A, 'x');
        }
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    } catch (Throwable $e) {
        echo "$fn: ".get_class($e).": ".$e->getMessage()."\n";
    }
}
