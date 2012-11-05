<?php
mb_internal_encoding("UTF-8");

$text = file_get_contents($argv[1]);
$text = mb_substr($text, mb_strpos($text,"InfoTableProposal"));
$text = mb_substr($text, 0, mb_strpos($text,"</table>"));

$lines = array_slice(mb_split("</tr>\s*<tr>",$text),1);
foreach ($lines as $line) {
	mb_ereg_search_init($line,"<td[^>]*>\s*(.*?)\s*</td>") or die("no data");
	while(mb_ereg_search()) {
		$r = mb_ereg_search_getregs();
		$r[1]=preg_replace("_\s+_ms"," ",$r[1]);
		echo $r[1]."|";
	}
	echo "\n";
}
?>
