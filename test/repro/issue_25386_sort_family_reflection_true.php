<?php
foreach (['sort','asort','arsort','ksort','krsort','shuffle','usort','uasort','uksort','natsort','natcasesort'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f, ':', $rf->hasReturnType() ? (string)$rf->getReturnType() : 'none', "\n";
}
