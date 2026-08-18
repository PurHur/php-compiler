<?php
// Repro #31966 — AOT base_convert() must compile (module verification) and match Zend.
var_dump(base_convert(255, 10, 2));
