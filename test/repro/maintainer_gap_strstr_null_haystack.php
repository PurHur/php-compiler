<?php
foreach (['strpos', 'stripos', 'strrpos', 'strripos', 'strstr', 'stristr', 'strchr', 'strrchr', 'strpbrk', 'strtok'] as $f) {
    try {
        $r = $f === 'strtok' ? strtok(null, '.') : $f(null, 'x');
        echo "$f: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "$f: TypeError\n";
    }
}
