<?php
// #31182 — unset PROFILE must parse-error C::{$name} like Zend 8.2
class C { const FOO = 'bar'; }
$name = 'FOO';
echo C::{$name}, "\n";
