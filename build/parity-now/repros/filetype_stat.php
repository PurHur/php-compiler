<?php
foreach (['filetype','filectime','fileatime','fileinode','fileowner','filegroup','fileperms'] as $f) {
  $rf = new ReflectionFunction($f);
  echo $f, ' ret=', $rf->hasReturnType() ? (string)$rf->getReturnType() : '(none)', "\n";
}
