<?php
// Issue #9646 — json_encode() flags: named parameter
var_export(json_encode(['a' => 1], flags: JSON_FORCE_OBJECT));
