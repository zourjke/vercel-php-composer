<?php
error_reporting(E_ALL);
header('Content-Type: text/json;charset=UTF-8');
date_default_timezone_set("Asia/Shanghai");

// 主IP和备用IP列表
$primaryIP = '67.159.6.34:8278';
$backupIPs = [
    '198.16.100.186:8278',
    '50.7.92.106:8278', 
    '50.7.220.170:8278'
];

// 生成随机token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

// 检查token有效性
session_start();
$token = $_GET["token"] ?? "";
$tokenValid = false;

if ($token) {
    if (isset($_SESSION['token']) && $_SESSION['token'] === $token) {
        $tokenValid = true;
    } else {
        $token = generateToken();
        $_SESSION['token'] = $token;
        $_SESSION['token_time'] = time();
    }
} else {
    $token = generateToken();
    $_SESSION['token'] = $token;
    $_SESSION['token_time'] = time();
}

// 检查token是否过期（40分钟）
if (isset($_SESSION['token_time']) && (time() - $_SESSION['token_time']) > 2400) {
    $token = generateToken();
    $_SESSION['token'] = $token;
    $_SESSION['token_time'] = time();
}

// 如果没有有效的token，则重定向
if (!$tokenValid) {
    $name = $_GET["id"] ?? "";
    $redirectUrl = $_SERVER['PHP_SELF'] . "?id=" . urlencode($name) . "&token=" . $token;
    header("Location: $redirectUrl");
    exit();
}

// 增强的curl_get函数，支持备用IP切换
function curl_get($url, $header = array(), $backupIPs = []) {
    $originalUrl = $url;
    $allIPs = array_merge([parse_url($url, PHP_URL_HOST)], $backupIPs);
    
    foreach ($allIPs as $ip) {
        $parsed = parse_url($url);
        $newUrl = str_replace($parsed['host'], $ip, $url);
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $newUrl,
            CURLOPT_HEADER => 0,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $header,
            CURLINFO_HEADER_OUT => true
        ]);
        
        $data = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200 && !curl_error($curl)) {
            curl_close($curl);
            return $data;
        }
        
        curl_close($curl);
    }
    
    return null;
}

// 主处理逻辑
$name = $_GET["id"] ?? "";
$ts = $_GET["ts"] ?? "";
$ip = '127.0.0.1';
$header = [
    "CLIENT-IP:" . $ip,
    "X-FORWARDED-FOR:" . $ip
];

if ($ts) {
    $host = "http://{$primaryIP}/{$name}/";
    $url = $host . $ts;
    $data = curl_get($url, $header, $backupIPs);
    
    if ($data === null) {
        header("Location: http://vjs.zencdn.net/v/oceans.mp4");
        exit();
    }
    
    echo $data;
} else {
    $url = "http://{$primaryIP}/{$name}/playlist.m3u8";
    $seed = "tvata nginx auth module";
    $path = parse_url($url, PHP_URL_PATH);
    $tid = "mc42afe745533";
    $t = strval(intval(time() / 150));
    $str = $seed . $path . $tid . $t;
    $tsum = md5($str);
    $link = http_build_query(["ct" => $t, "tsum" => $tsum]);
    $url .= "?tid=$tid&$link";
    $parseUrl = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    
    $result = curl_get($url, $header, $backupIPs);
    
    if (empty($result) || strpos($result, "404 Not Found") !== false) {
        header("Location: http://vjs.zencdn.net/v/oceans.mp4");
        exit();
    }
    
    if (strpos($result, "EXTM3U") !== false) {
        $m3u8s = explode("\n", $result);
        $result = '';
        foreach ($m3u8s as $v) {
            if (strpos($v, ".ts") !== false) {
                $result .= $parseUrl . "?id=" . $name . "&ts=" . $v . "&token=" . $token . "\n";
            } elseif ($v != '') {
                $result .= $v . "\n";
            }
        }
    }
    
    echo $result;
}
exit();
?>