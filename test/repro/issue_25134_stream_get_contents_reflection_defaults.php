<?php
$r = new ReflectionFunction('stream_get_contents');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=' . json_encode($p->getDefaultValue());
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
