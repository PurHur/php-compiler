<?php
/**
 * Reflection metadata parity for show_source() and filter_id() (#28756).
 */
$showSource = new ReflectionFunction('show_source');
$showSourceParams = $showSource->getParameters();
echo 'show_source.p0=', $showSourceParams[0]->hasType() ? (string) $showSourceParams[0]->getType() : 'none', "\n";
echo 'show_source.p1=', $showSourceParams[1]->hasType() ? (string) $showSourceParams[1]->getType() : 'none', "\n";
echo 'show_source.ret=', $showSource->hasReturnType() ? (string) $showSource->getReturnType() : 'none', "\n";

$filterId = new ReflectionFunction('filter_id');
echo 'filter_id.p0=', $filterId->getParameters()[0]->hasType() ? (string) $filterId->getParameters()[0]->getType() : 'none', "\n";
echo 'filter_id.ret=', $filterId->hasReturnType() ? (string) $filterId->getReturnType() : 'none', "\n";
echo 'filter_id.unknown=', var_export(filter_id('nosuchfilter'), true), "\n";
