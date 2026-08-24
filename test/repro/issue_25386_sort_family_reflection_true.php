<?php
foreach (['sort','asort','arsort','shuffle','usort','uasort','uksort','ksort','krsort','rsort','natsort','natcasesort'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f, ':', $rf->hasReturnType() ? (string)$rf->getReturnType() : 'none', "\n";
}
