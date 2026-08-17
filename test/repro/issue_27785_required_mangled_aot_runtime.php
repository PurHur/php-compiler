<?php
/**
 * #27785 — AOT runtime still returns array for get_required_files /
 * get_mangled_object_vars (Reflection metadata is VM-guarded; this probes the
 * native success path like peer #28174 getcwd AOT probe).
 */
$req = get_required_files();
$mangled = get_mangled_object_vars(new stdClass());
echo 'required_ok=', is_array($req) ? '1' : '0', "\n";
echo 'mangled_ok=', is_array($mangled) ? '1' : '0', "\n";
