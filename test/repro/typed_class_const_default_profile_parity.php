<?php
// Issue #24809 — typed class constants must parse-error on default / PROFILE=8.2 (Zend 8.2).
class T { public const string NAME = 'x'; }
echo T::NAME, "\n";
