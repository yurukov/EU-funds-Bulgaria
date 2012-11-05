<?php
set_error_handler('handleError');

$link = mysqli_connect('localhost', 'user', 'pass', "opendata_eu") or die('Could not connect: ' .$link->error);
$link->set_charset("utf8");

$pid=$argv[1];

echo "Working $pid...";
$res = $link->query("SELECT ProjectID FROM projects WHERE ProjectID=$pid limit 1") or die("\n\nError executing query: ". $link->error);
if ($res->num_rows>0) {
	echo "skip\n";
	exit;
}

echo " parsing...";
$data=parseDatJunk($pid);
echo " in DB...";
putDB($data);
echo "done\n";

/*
	data to SQL
*/

$link->close();
usleep(200000);

function putDB($data) {
	global $link;

	$projectId=$data["ProjectID"];
	
	secureProgram($data["OperativeProgramID"],$data["OperativeProgram"],$data["PartnersFinanceSource"]);
	secureEntity($data["BeneficientID"],$data["Beneficient"]);
	securePartner($data["Partners"], $projectId,"Partners");
	securePartner($data["Executors"], $projectId,"Executors");
	
	$data["ProjectRegionID"] = secureRegion($data["ProjectRegion"]);
	$data["BFP_EU_PaidAmount"] = securePayment($data["BFP_EU_FinanceByYearData"], $projectId, "EU");
	$data["BFP_National_PaidAmount"] = securePayment($data["BFP_National_FinanceByYearData"], $projectId, "National");

	secureIndicator($data["IndicatorsData"], $projectId);

	$data["ProjectNameISUN"] = "'".$link->escape_string($data["ProjectNameISUN"])."'";
	$data["ProjectNumber"] = $data["ProjectNumber"]=='' || $data["ProjectNumber"]=='---'  || $data["ProjectNumber"]==null ? "null" : "'".$link->escape_string($data["ProjectNumber"])."'";
	$cleanerList = array("Title", "RealApproved", "ProjectStart", "ProjectEnd", "ProjectStatus", "ProjectDescription", "ActivityList");
	foreach ($cleanerList as $cleanerKey) 
		$data[$cleanerKey] = $data[$cleanerKey]=='' || $data[$cleanerKey]==null ? "null" : "'".$link->escape_string($data[$cleanerKey])."'";
	$cleanerNumList = array("TotalBudget", "CommonBudget", "BFP_EU_AssumedAmount", "BFP_EU_PaidAmount", "BFP_National_AssumedAmount", "BFP_National_PaidAmount", "Benef_AssumedAmount");
	foreach ($cleanerNumList as $cleanerKey) 
		$data[$cleanerKey] = intval($data[$cleanerKey]);

	$query = "REPLACE INTO projects VALUE". 
	"(${data['ProjectID']},${data['ProjectNameISUN']},${data['ProjectNumber']},${data['Title']},${data['BeneficientID']},".
	"${data['OperativeProgramID']},${data['RealApproved']},${data['ProjectStart']},${data['ProjectEnd']},${data['ProjectStatus']},".
	"${data['ProjectRegionID']},${data['ProjectDescription']},${data['ActivityList']},".
	"${data['TotalBudget']},${data['CommonBudget']},${data['BFP_EU_AssumedAmount']},${data['BFP_EU_PaidAmount']},".
	"${data['BFP_National_AssumedAmount']},${data['BFP_National_PaidAmount']},${data['Benef_AssumedAmount']}, now())";

	$link->query($query) or die("\n\nError executing query: ". $link->error);
}

function secureIndicator($list, $projectId) {
	global $link;
	foreach ($list as $indicator) {
		$indicator[0]=$link->escape_string($indicator[0]);
		$indicator[1]=$link->escape_string($indicator[1]);
		$link->query("REPLACE INTO indicators VALUE ($projectId,'".$indicator[0]."','".$indicator[1]."')") or die("\n\nError executing query: ". $link->error);
	}
}

function securePayment($list, $projectId, $type) {
	global $link;
	$sum=0;
	foreach ($list as $payment) {
		if ($payment[1]=='0' || $payment[1]=='')
			continue;
		$payment[0]=intval($payment[0]);	
		$payment[1]=intval($payment[1]);	
		$sum+=$payment[1];
		$link->query("REPLACE INTO payments VALUE ($projectId,'$type',".$payment[0].",".$payment[1].")") or die("\n\nError executing query: ". $link->error);
	}
	return $sum;
}

function secureRegion($region, $parentId=null) {
	global $link;
	if (!$region || $region=='' || $region==';')
		return $parentId;

	$region=explode(";",$region);
	$region[0]=$link->escape_string($region[0]);
	$daId=null;
	$res = $link->query("SELECT ProjectRegionID FROM regions WHERE ProjectRegion='".$region[0]."' limit 1") or die("\n\nError executing query: ". $link->error);
	if ($res->num_rows>0) {
		$daId = $res->fetch_array(MYSQLI_NUM); 
		$daId = $daId[0];
	} else {
		$link->query("INSERT INTO regions (ProjectRegion,Parent) VALUE ('".$region[0]."',".($parentId==null?"null":$parentId).")") 
			or die("\n\nError executing query: ". $link->error);
		$daId = $link->insert_id;
	}
	$region = implode(";",array_slice($region,1));
	return secureRegion($region,$daId);
}

function secureProgram($id, $name, $parent) {
	global $link;
	if (!$id || $id=='' || !$name || $name=='')
		return;
	$id=intval($id);
	$name=$link->escape_string($name);
	$parent=$link->escape_string($parent);
	$res = $link->query("SELECT OperativeProgramID FROM programs WHERE OperativeProgramID=$id limit 1") or die("\n\nError executing query: ". $link->error);
	if ($res->num_rows==0)
		$link->query("INSERT INTO programs VALUE ($id,'$name',".($parent==''?"null":"'$parent'").")") or die("\n\nError executing query: ". $link->error);
}

function securePartner($list,$projectId, $type) {
	global $link;
	$projectId=intval($projectId);
	foreach ($list as $entity) {
		secureEntity($entity[0],$entity[1]);
		$entity[0]=intval($entity[0]);
		$link->query("REPLACE INTO partners VALUE ($projectId,".$entity[0].",'$type')") or die("\n\nError executing query: ". $link->error);
	}
}

function secureEntity($id, $name) {
	global $link;
	if (!$id || $id=='' || !$name || $name=='')
		return;
	$id=intval($id);
	$name=$link->escape_string($name);
	$res = $link->query("SELECT EntityID FROM entites WHERE EntityID=$id limit 1") or die("\n\nError executing query: ". $link->error);
	if ($res->num_rows==0)
		$link->query("INSERT INTO entites VALUE ($id,'$name',null)") or die("\n\nError executing query: ". $link->error);
}



/*
	HTML structure parsing
*/

function parseDatJunk($projectid) {
	$file = file_get_contents("../cache/project_$projectid.html" );
	$file = strstr($file,"<div id=\"contentwrapperDebt\"");
	$file = substr($file,0,strrpos($file,"</div>"));
	$file = str_replace("</br>","",$file);
	$file = str_replace("&nbsp"," ",str_replace("&nbsp;"," ",$file));
	$file = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'.$file;

	try {
		$xml = new DOMDocument('1.0', 'UTF-8'); 
		$xml->loadHTML( $file ) or die("error in parsing"); 
	} catch (ErrorException $e) {
		if (strpos($e->getMessage(),"no name in Entity")===false && 
			strpos($e->getMessage(),"expecting ';' in Entity")===false) {
			die("error in parsing: ".$e->getMessage()."\n\n"); 
		}
	}
	$xpath = new DOMXPath($xml);

	$data=array();

	$data["ProjectID"]=$projectid;

	$data["ProjectNameISUN"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectNameISUN_Text']")->item(0)->nodeValue);
	$data["ProjectNumber"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectNumber_Text']")->item(0)->nodeValue);
	$data["Title"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblTitle_Text']")->item(0)->nodeValue);

	$beneficient = $xpath->query("//a[@id='ContentPlaceHolder1_hplBeneficient']")->item(0);
	$data["BeneficientID"] = cleanUrlId($beneficient->getAttribute("href"));
	$data["Beneficient"] = cleanText($beneficient->nodeValue);

	$data["PartnersFinanceSource"] = $xpath->query("//span[@id='ContentPlaceHolder1_lblPartnersFinanceSource']")->item(0)->nodeValue;
	$data["PartnersFinanceSource"] = cleanText(substr($data["PartnersFinanceSource"], 0, strpos($data["PartnersFinanceSource"]," ")));

	$operativeProgram = $xpath->query("//a[@id='ContentPlaceHolder1_hplOperativeProgram']")->item(0);
	$data["OperativeProgramID"] = cleanUrlId($operativeProgram->getAttribute("href"));
	$data["OperativeProgram"] = cleanText($operativeProgram->nodeValue);

	$data["RealApproved"] = cleanDate($xpath->query("//span[@id='ContentPlaceHolder1_lblRealApproved_Text']")->item(0)->nodeValue);
	$data["ProjectStart"] = cleanDate($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectStart_Text']")->item(0)->nodeValue);
	$data["ProjectEnd"] = cleanDate($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectEnd_Text']")->item(0)->nodeValue);
	$data["ProjectStatus"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectStatus_Text']")->item(0)->nodeValue);

	$data["ProjectRegion"] = $xpath->query("//span[@id='ContentPlaceHolder1_lblProjectRegion_Text']")->item(0)->textContent;
	$data["ProjectRegion"] = cleanText(preg_replace("_\s{2,100}_",";",$data["ProjectRegion"]));

	$data["ProjectDescription"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblProjectDescription']")->item(0)->nodeValue);
	$data["ActivityList"] = cleanText($xpath->query("//span[@id='ContentPlaceHolder1_lblActivityList']")->item(0)->nodeValue);

	$partners = $xpath->query("//a[starts-with(@id,'ContentPlaceHolder1_rptPartners_hplPartners')]");
	$data["Partners"]=array();	
	for ($i=0;$i<$partners->length;$i++) {
		$id=cleanUrlId($partners->item($i)->getAttribute("href"));
		$name=cleanText($partners->item($i)->nodeValue);
		$data["Partners"][]=array($id, $name);
	}

	$executors = $xpath->query("//a[starts-with(@id,'ContentPlaceHolder1_rptExecutors_hplExecutors')]");
	$data["Executors"]=array();	
	for ($i=0;$i<$executors->length;$i++) {
		$id=cleanUrlId($executors->item($i)->getAttribute("href"));
		$name=cleanText($executors->item($i)->nodeValue);
		$data["Executors"][]=array($id, $name);
	}

	$data["TotalBudget"] = cleanMoney($xpath->query("//span[@id='ContentPlaceHolder1_lblTotalBudget_Text']")->item(0)->nodeValue);
	$data["CommonBudget"] = cleanMoney($xpath->query("//span[@id='ContentPlaceHolder1_lblCommonBudget']")->item(0)->nodeValue);
	$data["BFP"] = cleanMoney($xpath->query("//span[@id='ContentPlaceHolder1_lblBFP']")->item(0)->nodeValue);
	$data["CommonPayment"] = cleanMoney($xpath->query("//span[@id='ContentPlaceHolder1_lblCommonPayment']")->item(0)->nodeValue);

	$BFP_FinanceByYearData = $xpath->query("//tr[@id='ContentPlaceHolder1_trBFP_FinanceByYearData']//tr");
	$data["BFP_FinanceByYearData"]=array();	
	for ($i=0;$i<$BFP_FinanceByYearData->length;$i++) {
		$year=$BFP_FinanceByYearData->item($i)->childNodes->item(0)->nodeValue;
		$amount=cleanMoney($BFP_FinanceByYearData->item($i)->childNodes->item(2)->nodeValue);
		$data["BFP_FinanceByYearData"][]=array($year,$amount);
	}

	$data["BFP_EU_AssumedAmount"] = cleanMoney($xpath->query("//td[@id='ContentPlaceHolder1_tdBFP_EU_AssumedAmount']")->item(0)->nodeValue);

	$BFP_EU_FinanceByYearData = $xpath->query("//tr[@id='ContentPlaceHolder1_trBFP_EU_FinanceByYearData']//tr");
	$data["BFP_EU_FinanceByYearData"]=array();	
	for ($i=0;$i<$BFP_EU_FinanceByYearData->length;$i++) {
		$year=$BFP_EU_FinanceByYearData->item($i)->childNodes->item(0)->nodeValue;
		$amount=cleanMoney($BFP_EU_FinanceByYearData->item($i)->childNodes->item(2)->nodeValue);
		$data["BFP_EU_FinanceByYearData"][]=array($year,$amount);
	}

	$data["BFP_National_AssumedAmount"] = cleanMoney($xpath->query("//td[@id='ContentPlaceHolder1_tdBFP_National_AssumedAmount']")->item(0)->nodeValue);

	$BFP_National_FinanceByYearData = $xpath->query("//tr[@id='ContentPlaceHolder1_trBFP_National_FinanceByYearData']//tr");
	$data["BFP_National_FinanceByYearData"]=array();	
	for ($i=0;$i<$BFP_National_FinanceByYearData->length;$i++) {
		$year=$BFP_National_FinanceByYearData->item($i)->childNodes->item(0)->nodeValue;
		$amount=cleanMoney($BFP_National_FinanceByYearData->item($i)->childNodes->item(2)->nodeValue);
		$data["BFP_National_FinanceByYearData"][]=array($year,$amount);
	}

	$data["Benef_AssumedAmount"] = cleanMoney($xpath->query("//td[@id='ContentPlaceHolder1_tdBenef_AssumedAmount']")->item(0)->nodeValue);

	$IndicatorsData = $xpath->query("//td[@id='ContentPlaceHolder1_tdIndicatorsData']//tr");
	$data["IndicatorsData"]=array();	
	for ($i=0;$i<$IndicatorsData->length;$i++) {
		$id=cleanText(str_replace("Индикатор ","",$IndicatorsData->item($i)->childNodes->item(0)->nodeValue));
		$indic=cleanText($IndicatorsData->item($i)->childNodes->item(2)->nodeValue);
		$data["IndicatorsData"][]=array($id,$indic);
	}
	return $data;
}

function cleanUrlId($text) {
	return substr($text, strpos($text,"=")+1);
}
function cleanMoney($text) {
	return str_replace(array(" ","BGN"),"",$text);
}
function cleanText($text) {
	return trim(preg_replace("_\s+_"," ",$text));
}
function cleanDate($text) {
	return substr($text,0,10);
}


function handleError($errno, $errstr, $errfile, $errline, array $errcontext)
{
    if (0 === error_reporting()) {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

?>
