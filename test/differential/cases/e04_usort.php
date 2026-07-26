<?php $a=[3,1,2]; usort($a, function($p,$q){ return $p <=> $q; }); print_r($a);
