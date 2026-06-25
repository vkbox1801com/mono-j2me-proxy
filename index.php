<?php
// 1. Включаем отображение всех ошибок PHP для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
$action = isset($_GET['action']) ? trim($_GET['action']) : 'balance';

// Извлекаем X-Token из заголовков запроса от Java-приложения
$headers = getallheaders();
$token = '';
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-token') {
        $token = trim($value);
        break;
    }
}

if (empty($token)) {
    echo "MAIN_PAN: Ошибка\nMAIN_BAL: Нет токена\nINFO: Передайте заголовок X-Token в приложении.";
    exit;
}

// --- В К Л А Д К А :   К У Р С Ы   В А Л Ю Т ---
if ($action === 'currency') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/bank/currency');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo "MAIN_PAN: Курсы валют\nMAIN_BAL: Ошибка $http_code\nINFO: Монобанк просит подождать (лимит запросов).";
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        echo "MAIN_PAN: Курсы валют\nMAIN_BAL: Сбой JSON\nINFO: Не удалось обработать данные.";
        exit;
    }

    echo "MAIN_PAN: Курсы валют\nMAIN_BAL: НБУ / Mono\n";
    foreach ($data as $c) {
        // Ищем USD (840) и EUR (978) по отношению к UAH (980)
        if (($c['currencyCodeA'] == 840 || $c['currencyCodeA'] == 978) && $c['currencyCodeB'] == 980) {
            $currencyName = ($c['currencyCodeA'] == 840) ? "USD" : "EUR";
            $rateBuy = isset($c['rateBuy']) ? number_format($c['rateBuy'], 2, '.', '') : '0.00';
            $rateSell = isset($c['rateSell']) ? number_format($c['rateSell'], 2, '.', '') : '0.00';
            
            echo "INFO: $currencyName: Покупка $rateBuy / Прод. $rateSell\n";
        }
    }
    exit;
}

// --- В К Л А Д К А :   Б А Л А Н С   И   К А Р Т Ы ---
if ($action === 'balance') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/personal/client-info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo "MAIN_PAN: Ошибка\nMAIN_BAL: Сбой $http_code\nINFO: Проверьте ПИН/Токен или повторите позже.";
        exit;
    }

    $res = json_decode($response, true);
    if (isset($res['accounts']) && is_array($res['accounts'])) {
        $mainPan = "";
        $mainBal = "";
        $hasMain = false;
        $infoLines = array();

        $infoLines[] = "Клиент: " . $res['name'];

        foreach ($res['accounts'] as $acc) {
            $bal = number_format($acc['balance'] / 100, 2, '.', '');
            $cur = ($acc['currencyCode'] === 980) ? "UAH" : (($acc['currencyCode'] === 840) ? "USD" : "EUR");
            
            // Если у аккаунта есть номер карты
            if (isset($acc['maskedPan'][0])) {
                $pan = "Карта (.." . substr($acc['maskedPan'][0], -4) . ")";
                
                // Самую первую найденную карту выводим в главный виджет сверху экрана
                if (!$hasMain) {
                    $mainPan = $pan;
                    $mainBal = "$bal $cur";
                    $hasMain = true;
                }
                $infoLines[] = "$pan: $bal $cur";
            } else {
                // Если это виртуальный счет, банка или ФОП
                $infoLines[] = "Счет (" . $acc['type'] . "): $bal $cur";
            }
        }

        // Отправляем структурированный ответ для Java-приложения
        echo "MAIN_PAN: " . (empty($mainPan) ? "Основной счет" : $mainPan) . "\n";
        echo "MAIN_BAL: " . (empty($mainBal) ? "0.00 UAH" : $mainBal) . "\n";
        foreach ($infoLines as $line) {
            echo "INFO: $line\n";
        }
    } else {
        echo "MAIN_PAN: Ошибка\nMAIN_BAL: JSON Error\nINFO: Не удалось прочитать список аккаунтов.";
    }
    exit;
}
