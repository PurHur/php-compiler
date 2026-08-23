<?php
$e = new ReflectionExtension('date');
echo 'persistent='.var_export($e->isPersistent(), true)
    .'|temporary='.var_export($e->isTemporary(), true)
    ."\n";
