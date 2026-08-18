<?php
// Repro #31966 — AOT `2 ** 10` must compile (module verification) and match Zend.
var_dump(2 ** 10);
