<?php
/**
 * WHMCS-JSJ-API-Pay-Gateway
 *
 * @author tutugreen (yuanming@tutugreen.com)
 * @copyright Copyright (c) 2016~2019 Tutugreen.com
 * @license MIT
 * @link	https://github.com/tutugreen/WHMCS-JSJ-API-Pay-Gateway
 *
 */

# Required File Includes
require_once("../../../init.php");
require_once("../../../includes/functions.php");
require_once("../../../includes/gatewayfunctions.php");
require_once("../../../includes/invoicefunctions.php");
require_once("../JSJApiPay/JSJApiPay.class.php");

#Load
use Illuminate\Database\Capsule\Manager as Capsule;

$gatewaymodule = "JSJApiPay";

//预设参数
$transid_header = "JSJApiPay_";//可自定义WHMCS交易流水号前缀，一旦使用请保持一致，后期修改存在刷单风险。

if ($_POST['payment_type'] or $_GET['payment_type']){
	$incoming_payment_type = $_POST['payment_type'] ? $_POST['payment_type'] : $_GET['payment_type'];
	if ($incoming_payment_type == 'alipay_web'){
		$gatewaymodule = "JSJApiPay_Alipay_Web";
	} elseif ($incoming_payment_type == 'alipay_wap'){
		$gatewaymodule = "JSJApiPay_Alipay_Wap";
	} elseif ($incoming_payment_type == 'alipay_qrcode'){
		$gatewaymodule = "JSJApiPay_Alipay_QRCode";
	} elseif ($incoming_payment_type == 'wechat_pay_qrcode'){
		$gatewaymodule = "JSJApiPay_WeChat_Pay_QRCode";
	} elseif ($incoming_payment_type == 'qq_pay_qrcode'){
		$gatewaymodule = "JSJApiPay_QQ_Pay_QRCode";
	} elseif ($incoming_payment_type == 'card_redeem'){
		$gatewaymodule = "JSJApiPay_Card_Redeem";
	} elseif ($incoming_payment_type == 'card_connect'){
		$gatewaymodule = "JSJApiPay_Card_Connect";
	} else {
		if ($debug) {
			$msg="[JSJApiPay]收到未知回调，payment_type 变量缺失或错误";
			JSJApiPay_logResult($msg);
		}
		$api_pay_failed = "true";
		echo "非法访问";
		exit;
	}
}else{
	if ($debug) {
		$msg="[JSJApiPay]收到未知回调，payment_type 变量缺失或错误";
		JSJApiPay_logResult($msg);
	}
	$api_pay_failed = "true";
	echo "非法访问";
	exit;
}

if ($_POST['act'] or $_GET['act']){
}else{
	if ($debug) {
		$act = $_POST['act'] ? $_POST['act'] : $_GET['act'];
		$msg="[JSJApiPay]收到未知回调，act 变量缺失或错误";
		JSJApiPay_logResult($msg);
	}
	$api_pay_failed = "true";
	echo "非法访问";
}

if ($api_pay_failed<>"true"){
	$gateway = getGatewayVariables($gatewaymodule);
	if (!$gateway["type"]) die("Module Not Activated，您所请求的回调接口未启用！"); # Checks gateway module is active before accepting callback
	//Var Check
		if (!isset($gateway['apiid'])) {
		echo '$apiid(合作伙伴ID) 为必填项目，请在后台-系统设置-付款-支付接口，Manage Existing Gateways 选项卡中设置。';
		exit;
		}
		if (!isset($gateway['apikey'])) {
		echo '$apikey(安全检验码) 为必填项目，请在后台-系统设置-付款-支付接口设置，Manage Existing Gateways 选项卡中设置。';
		exit;
		}
		if (!isset($gateway['fee_acc'])) {
		echo '$fee_acc(记账手续费) 为必填项目，请在后台-系统设置-付款-支付接口设置，Manage Existing Gateways 选项卡中设置。';
		exit;
		}

	//Start
		$JSJApiPay_config['apiid'] = trim($gateway['apiid']);
		$JSJApiPay_config['apikey'] = trim($gateway['apikey']);
		$JSJApiPay_config['fee_acc'] = trim($gateway['fee_acc']);

	if ($act=='redeem' and $incoming_payment_type == 'card_redeem'){
		$incoming_invoiceid = $_POST['invoiceid'] ? $_POST['invoiceid'] : $_GET['invoiceid'];//账单ID
		if(!ctype_digit($incoming_invoiceid)){
			if ($debug) {
				$msg="[JSJApiPay]收到未知回调，invoiceid 变量缺失或错误";
				JSJApiPay_logResult($msg);
			}
			$api_pay_failed = "true";
			echo "核验失败。如需帮助，请联系客户支持。";
			exit;
		}
		$invoiceid = $incoming_invoiceid;
		$card = $_POST['card'] ? $_POST['card'] : $_GET['card'];//卡密
		if(!ctype_alnum($card) or strlen($card) > 32){
			if ($debug) {
				$msg="[JSJApiPay]收到未知回调，card 变量缺失或错误";
				JSJApiPay_logResult($msg);
			}
			$api_pay_failed = "true";
			echo "核验失败。如需帮助，请联系客户支持。";
			exit;
		}
		$transid = $transid_header."Card_".$card;
		$card_type = $_POST['card_type'] ? $_POST['card_type'] : $_GET['card_type'];//卡面金额
		if($card_type=="1"){//从
			$amount=number_format(trim($gateway['card_amount_1']),2,".","");
		}elseif($card_type=="2"){
			$amount=number_format(trim($gateway['card_amount_2']),2,".","");
		}elseif($card_type=="3"){
			$amount=number_format(trim($gateway['card_amount_3']),2,".","");
		}elseif($card_type=="4"){
			$amount=number_format(trim($gateway['card_amount_4']),2,".","");
		}elseif($card_type=="5"){
			$amount=number_format(trim($gateway['card_amount_5']),2,".","");
		}else{
			if ($debug) {
				$msg="[JSJApiPay]收到未知回调，amount 变量缺失或错误";
				JSJApiPay_logResult($msg);
			}
			$api_pay_failed = "true";
			echo "核验失败。如需帮助，请联系客户支持。";
			exit;
		}

		if(!get_magic_quotes_gpc()){
			$card = addslashes($card);
		}

		$tid = $_GET['tid'];
		//平台规则生成查询数据
		$query_key = md5($JSJApiPay_config['apikey'].$card);
		$curl_query_card_status_postfields = array('kami' => $card, 'apiid' => $JSJApiPay_config['apiid'], 'total' => $amount, 'chakey' => $query_key);

		//创建CURL，向API核销卡密
		$curl_query_card_status = curl_init();
		curl_setopt($curl_query_card_status, CURLOPT_URL, "https://yun.jsjapp.com/k/q_k.php");
		curl_setopt($curl_query_card_status, CURLOPT_POST, 1);
		curl_setopt($curl_query_card_status, CURLOPT_TIMEOUT, 3);
		curl_setopt($curl_query_card_status, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($curl_query_card_status, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($curl_query_card_status, CURLOPT_SSL_VERIFYHOST, true);
		curl_setopt($curl_query_card_status, CURLOPT_HEADER, 0);
		curl_setopt($curl_query_card_status, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl_query_card_status, CURLOPT_POSTFIELDS, $curl_query_card_status_postfields);
		curl_setopt($curl_query_card_status, CURLOPT_USERAGENT, "WHMCS_PHP_CURL");
		//存储字符
		$curl_query_card_status_data = trim(trim(curl_exec($curl_query_card_status)), "\xEF\xBB\xBF");
		//关闭CURL
		curl_close($curl_query_card_status);

		$curl_query_card_status_data = trim($curl_query_card_status_data, "\xEF\xBB\xBF");
		if($curl_query_card_status_data=="9"){			//卡密已被使用过
			echo "销卡失败，卡密已被使用过，请检查账单状态。如需帮助，请联系客户支持。";
			exit;
		}else if($curl_query_card_status_data=="4"){		//卡密无效
			echo "核验失败。如需帮助，请联系客户支持。";
			//var_dump($curl_query_card_status_postfields);//调试
			exit;
		}else if($curl_query_card_status_data=="1"){		//判断正确，可以使用的卡密，首次销卡。
			//正确的路径，合法的参数，的确支付过的会员
			/********************************************
				这里根据业务逻辑编写相应的程序代码。
				1、（本条由WHMCS处理）checkCbInvoiceID 会确认交易流水号的唯一性，防止刷单，如存在将exit自动停止。
				2、（本条由WHMCS处理）addInvoicePayment 会校验交易基本信息，包括金额，完成自动入账，失败将自动exit退出。
			********************************************/
			# convert the currency where necessary
			$userCurrency = getCurrency(Capsule::table("tblinvoices")->where("id",$invoiceid)->get()[0]->userid);
			$userCurrency_id = $userCurrency["id"];
			$userCurrency_suffix = $userCurrency["suffix"];
			if($gateway['convertto'] && ($userCurrency != $gateway['convertto'])) {
				# the users currency is not the same as the JSJApiPay currency, convert to the users currency
				$amount = convertCurrency($amount,$gateway['convertto'],$userCurrency_id);
				$fee = convertCurrency($fee,$gateway['convertto'],$userCurrency_id);
			}
			if ($debug) JSJApiPay_logResult("[JSJApiPay]订单 $invoiceid 兑换验证成功，如入账成功详细参数可在WHMCS-财务记录-接口日志(网关事务日志)中查看");
			$invoiceid = checkCbInvoiceID($invoiceid,$gateway["name"]); # Checks invoice ID is a valid invoice number or ends processing
			checkCbTransID($transid);
			addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule);
			logTransaction($gateway["name"],$_POST,"Successful-A");
			//注意，如果你的WHMCS目录比较特殊或需要修改目的地，请在这里修改回调目的地，改为你的账单页面或其他。
			////header("location:../../../viewinvoice.php?id=$invoiceid&from=paygateway&status=redeemsuccess");
			$html_code = <<<HTML_CODE
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>核验结果</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style=" font-family: Microsoft YaHei;">
<script type="text/javascript">
alert("卡密核销成功，感谢您的付款，金额已添加到账单，请关注交易流水。\\n\\n提示：如卡面金额有结余，将自动冲入账户余额。");
window.location="../../../viewinvoice.php?id={$invoiceid}&from=paygateway&status=redeemsuccess";

</script>
</body>
</html>
HTML_CODE;
			echo $html_code;
			exit;
		}else{
			echo "核验失败。如需帮助，请联系客户支持。";
			exit;
		}
	}

	if ($act=='callback' and $incoming_payment_type == 'card_connect'){
		//参数获取
		//自动判断POST/GET
		$incoming_addnum = $_POST['addnum'] ? $_POST['addnum'] : $_GET['addnum'];//订单编号
		$incoming_total = $_POST['total'] ? $_POST['total'] : $_GET['total'];//支付金额
		$incoming_sort = $_POST['sort'] ? $_POST['sort'] : $_GET['sort'];//数字(1支付宝2微信3QQ通道)，接口名称分离，无验证，不作为可靠参数
		$incoming_uid = $_POST['uid'] ? $_POST['uid'] : $_GET['uid'];//$uid 现作为订单号使用，上限99999999
		$incoming_apikey = $_POST['apikey'] ? $_POST['apikey'] : $_GET['apikey'];//md5(您的apikey+addnum+uid+total)
		//$incoming_info = $_POST['info'] ? $_POST['info'] : $_GET['info'];//卡密信息，不做判断和使用

		//参数转化
		$addnum     = trim($incoming_addnum);    //订单信息
		$amount     = trim($incoming_total);     //支付金额
		$invoiceid  = trim($incoming_uid);       //支付会员(代替订单号)ID
		$apikey     = trim($incoming_apikey);     //传入的回调Key
		$transid    = $transid_header."Card_Connect_".$invoiceid."_".$addnum;        //订单流水传递
		$invoiceid  = trim($incoming_uid);       //支付会员(代替订单号)ID

		//手续费计算
		$fee        = $amount*$JSJApiPay_config['fee_acc'];

		//验证回调key

		if($apikey == md5($JSJApiPay_config['apikey'].$addnum.$invoiceid.$amount)){
			$apikey_validate_result = "Success";
		} else {
			$apikey_validate_result = "Failed";
		}

		if ($apikey_validate_result != "Success"){
			$api_pay_failed = "true";
			logTransaction($gateway["name"],$_GET.$_POST,"Unsuccessfull-APIKEY-Validate-Failed");
			exit;
		} else {
			//checkCbInvoiceID 会确认交易流水号的唯一性，防止刷单，如存在将exit自动停止。
			//addInvoicePayment 会校验交易基本信息，包括金额，完成自动入账，失败将自动exit退出。
			# convert the currency where necessary
			$userCurrency = getCurrency(Capsule::table("tblinvoices")->where("id",$invoiceid)->get()[0]->userid);
			$userCurrency_id = $userCurrency["id"];
			$userCurrency_suffix = $userCurrency["suffix"];
			if($gateway['convertto'] && ($userCurrency != $gateway['convertto'])) {
				# the users currency is not the same as the JSJApiPay currency, convert to the users currency
				$amount = convertCurrency($amount,$gateway['convertto'],$userCurrency_id);
				$fee = convertCurrency($fee,$gateway['convertto'],$userCurrency_id);
			}
			if ($debug) JSJApiPay_logResult("[JSJApiPay]订单 $invoiceid 回调验证成功，如入账成功详细参数可在WHMCS-财务记录-接口日志(网关事务日志)中查看");
			//注意，如果你的WHMCS目录比较特殊或需要修改目的地，请在这里修改回调目的地，改为你的账单页面或其他。
			if($_SERVER['HTTP_USER_AGENT'] && $_SERVER['HTTP_USER_AGENT'] !=""){
				//header("location:../../../viewinvoice.php?id=$invoiceid&from=paygateway&status=waitsuccess");
				echo "success";
			}else{
				echo "success";
			}
			$invoiceid = checkCbInvoiceID($invoiceid,$gateway["name"]); # Checks invoice ID is a valid invoice number or ends processing
			checkCbTransID($transid);
			addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule);
			logTransaction($gateway["name"],$_POST,"Successful-A");
			exit;
		}
	}

	if ($act=='return' or $act=='bd'){
		//回调地址
		/********************************************
			这里会传过来几个参数 分别为：
			$_POST['addnum'] 订单编号
			$_POST['total']  支付金额
			$_POST['uid']    支付会员ID
			$_POST['apikey'] 您的(apikey+订单号)小写md5加密，下文称之为回调key
		********************************************/

		//参数获取
		//自动判断POST/GET
		$incoming_apikey = $_POST['apikey'] ? $_POST['apikey'] : $_GET['apikey'];//$apikey 为接收到的回调key,用于认证
		$incoming_addnum = $_POST['addnum'] ? $_POST['addnum'] : $_GET['addnum'];//$addnum 为接收到的订单编号
		$incoming_uid = $_POST['uid'] ? $_POST['uid'] : $_GET['uid'];//$uid 现作为订单号使用，上限99999999
		$incoming_total = $_POST['total'] ? $_POST['total'] : $_GET['total'];//$total 为接收到的支付金额

		$incoming_invoiceid = $_POST['invoiceid'] ? $_POST['invoiceid'] : $_GET['invoiceid'];//$invoiceid 作为回调地址内订单号，一般使用GET。

		//参数转化
		$addnum     = trim($incoming_addnum);    //订单信息
		$amount     = trim($incoming_total);     //支付金额
		$invoiceid  = trim($incoming_uid);       //支付会员(代替订单号)ID
		$apikey     = trim($incoming_apikey);     //传入的回调Key
		$transid    = $transid_header.$addnum;        //订单流水传递

		if(empty($incoming_uid)){
			$invoiceid  = trim($incoming_invoiceid); //兼容用户跳转
		}

		//手续费计算
		$fee        = $amount*$JSJApiPay_config['fee_acc'];

		//验证回调key

        if ($gatewaymodule == "JSJApiPay_Alipay_Web" or $gatewaymodule == "JSJApiPay_Alipay_Wap" or $gatewaymodule == "JSJApiPay_Alipay_QRCode" or $gatewaymodule == "JSJApiPay_WeChat_Pay_QRCode" or $gatewaymodule == "JSJApiPay_QQ_Pay_QRCode" or $gatewaymodule == "JSJApiPay_Card_Connect"){
			if($apikey == md5($JSJApiPay_config['apikey'].$addnum.$invoiceid.$amount)){
				$apikey_validate_result = "Success";
			} else {
				$apikey_validate_result = "Failed";
			}
		}

		if ($apikey_validate_result != "Success"){
			$api_pay_failed = "true";
			if ($gatewaymodule == "JSJApiPay_Alipay_Wap"){
				//Wap同步回调比较特殊，不带参数，此处暂不对其订单判断结果，直接转到账单页等待异步更新（如支付成功会自动刷新）。
				logTransaction($gateway["name"],$_GET.$_POST.$_SERVER['HTTP_USER_AGENT'],"Client Reach Invoice Page.");
				header("location:../../../viewinvoice.php?id=$invoiceid&from=paygateway&status=waitsuccess");
				exit;
			} else {
				//取消异步回调后，统一跳转到账单
				if($_SERVER['HTTP_USER_AGENT'] && $_SERVER['HTTP_USER_AGENT'] !=""){
					logTransaction($gateway["name"],$_GET.$_POST.$_SERVER['HTTP_USER_AGENT'],"Client Reach Invoice Page.");
				}else{
					logTransaction($gateway["name"],$_GET.$_POST,"Unsuccessfull-APIKEY-Validate-Failed");
				}
				if($_SERVER['HTTP_USER_AGENT']){
				if($_SERVER['HTTP_USER_AGENT'] && $_SERVER['HTTP_USER_AGENT'] !=""){
					header("location:../../../viewinvoice.php?id=$invoiceid&from=paygateway&status=waitsuccess");
					exit;
				}else{
					echo "error";
					exit;
				}
				}else{
					echo "error";
					exit;
				}
			}
			exit;
		} else {
			//checkCbInvoiceID 会确认交易流水号的唯一性，防止刷单，如存在将exit自动停止。
			//addInvoicePayment 会校验交易基本信息，包括金额，完成自动入账，失败将自动exit退出。
			# convert the currency where necessary
			$userCurrency = getCurrency(Capsule::table("tblinvoices")->where("id",$invoiceid)->get()[0]->userid);
			$userCurrency_id = $userCurrency["id"];
			$userCurrency_suffix = $userCurrency["suffix"];
			if($gateway['convertto'] && ($userCurrency != $gateway['convertto'])) {
				# the users currency is not the same as the JSJApiPay currency, convert to the users currency
				$amount = convertCurrency($amount,$gateway['convertto'],$userCurrency_id);
				$fee = convertCurrency($fee,$gateway['convertto'],$userCurrency_id);
			}
			if ($debug) JSJApiPay_logResult("[JSJApiPay]订单 $invoiceid 回调验证成功，如入账成功详细参数可在WHMCS-财务记录-接口日志(网关事务日志)中查看");
			//注意，如果你的WHMCS目录比较特殊或需要修改目的地，请在这里修改回调目的地，改为你的账单页面或其他。
			if($_SERVER['HTTP_USER_AGENT'] && $_SERVER['HTTP_USER_AGENT'] !=""){
				header("location:../../../viewinvoice.php?id=$invoiceid&from=paygateway&status=waitsuccess");
			}else{
				echo "success";
			}
			$invoiceid = checkCbInvoiceID($invoiceid,$gateway["name"]); # Checks invoice ID is a valid invoice number or ends processing
			checkCbTransID($transid);
			addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule);
			logTransaction($gateway["name"],$_POST,"Successful-A");
			exit;
		}
	}
}
?>
