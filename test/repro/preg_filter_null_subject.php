<?php

// Repro #18698: preg_filter(null $subject) must return NULL, not TypeError.
var_export(preg_filter('/a/', 'b', null));
