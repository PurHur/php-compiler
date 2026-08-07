<?php
declare(strict_types=1);

// Repro: DOMDocument::load* Reflection return bool + loadHTML* options int (#28713).
foreach (['load', 'loadXML', 'loadHTML', 'loadHTMLFile'] as $m) {
    $rf = new ReflectionMethod(DOMDocument::class, $m);
    echo $m, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', "\n";
    $opts = $rf->getParameters()[1] ?? null;
    if (null !== $opts) {
        echo '  options type=', $opts->hasType() ? (string) $opts->getType() : 'none';
        if ($opts->isDefaultValueAvailable()) {
            echo ' def=', var_export($opts->getDefaultValue(), true);
        }
        echo "\n";
    }
}
