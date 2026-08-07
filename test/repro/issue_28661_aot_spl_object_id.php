<?php
// Issue #28661 — AOT spl_object_id() must return a positive stable handle (not SIGABRT).
$o = new stdClass;
$id = spl_object_id($o);
echo 'id=', $id, "\n";
echo 'same=', (spl_object_id($o) === $id ? 'yes' : 'no'), "\n";
echo 'positive=', ($id > 0 ? 'yes' : 'no'), "\n";
$o2 = new stdClass;
echo 'distinct=', (spl_object_id($o2) !== $id ? 'yes' : 'no'), "\n";
