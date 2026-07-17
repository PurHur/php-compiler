<?php
/** Repro #20005 — $sxe->child["attr"] = via property→dim (sxe_prop_dim_write). */
$xml = simplexml_load_string('<root><item>1</item></root>');
$xml->item['id'] = 'x';
echo (string) $xml->item['id'], "\n";
echo trim($xml->asXML()), "\n";
$xml2 = simplexml_load_string('<root><item>1</item></root>');
$xml2->item[0]['id'] = 'y';
echo (string) $xml2->item['id'], "\n";
echo trim($xml2->asXML()), "\n";
