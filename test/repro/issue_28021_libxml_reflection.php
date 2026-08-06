<?php

foreach ([
    'libxml_clear_errors',
    'libxml_get_last_error',
    'libxml_get_external_entity_loader',
    'libxml_set_streams_context',
    'libxml_disable_entity_loader',
] as $name) {
    $rf = new ReflectionFunction($name);
    $ret = $rf->hasReturnType() ? (string) $rf->getReturnType() : '<none>';
    echo $name.' ret='.$ret;
    if ($rf->getNumberOfParameters() > 0) {
        $param = $rf->getParameters()[0];
        echo ' '.$param->getName().'=';
        echo $param->isDefaultValueAvailable() ? var_export($param->getDefaultValue(), true) : 'none';
    }
    echo "\n";
}
