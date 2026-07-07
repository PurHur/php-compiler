<?php

declare(strict_types=1);

// Maintainer gap #17075 — copy(null, …) under strict_types must TypeError (ext/standard/file.c).
foreach (['from' => [null, '/tmp/x'], 'to' => ['/tmp/x', null]] as $label => [$from, $to]) {
    try {
        copy($from, $to);
        echo $label, "=uncaught\n";
    } catch (TypeError $e) {
        echo $label, '=TypeError', "\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), "\n";
    }
}
