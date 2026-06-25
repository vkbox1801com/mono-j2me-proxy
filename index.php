<?php
// 1. Включаем отображение всех ошибок PHP для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) J2ME-Proxy';

// Получаем параметры из J2ME приложения
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// Извлекаем X-Token из заголовков запроса (J2ME шлет его именно туда)
$headers = getallheaders();
$token = '';
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-token') {
        $token = trim($value);
        break;
    }
}

// 2. Логируем входящий запрос для отладки в файл request.log
$debug_info = "--- ЗАПРОС " . date('Y-m-d H:i:s') . " ---\n";
$debug_info .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$debug_info .= "Action: " . $action . "\n";
$debug_info .= "Token: " . (empty($token) ? 'ОТСУТСТВУЕТ' : substr($token, 0, 5) . '...') . "\n";
$debug_info .= "---------------------------------------\n";
file_put_contents('request.log', $debug_info, FILE_APPEND);


// --- КУРСЫ ВАЛЮТ (Публичный метод, токен не нужен) ---
if ($action === 'currency') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/bank/currency');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo "Ошибка cURL (Валюта): " . curl_error($ch);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    if ($http_code !== 200) {
        echo "Монобанк вернул код: $http_code\nОтвет: $response";
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        echo "Ошибка: Не удалось распарсить JSON валют.";
        exit;
    }

    echo "--- КУРСЫ ВАЛЮТ ---\n";
    foreach ($data as $c) {
        // USD -> UAH
        if ($c['currencyCodeA'] == 840 && $c['currencyCodeB'] == 980) {
            $buy = number_format($c['rateBuy'], 2, '.', '');
            $sell = number_format($c['rateSell'], 2, '.', '');
            echo "USD: Покупка $buy / Прод. $sell\n";
        }
        // EUR -> UAH
        if ($c['currencyCodeA'] == 978 && $c['currencyCodeB'] == 980) {
            $buy = number_format($c['rateBuy'], 2, '.', '');
            $sell = number_format($c['rateSell'], 2, '.', '');
            echo "EUR: Покупка $buy / Прод. $sell\n";
        }
    }
    exit;
}


// Для всех остальных (приватных) методов проверяем наличие токена
if (empty($token)) {
    echo "Ошибка: Приложение не передало X-Token в заголовках!";
    exit;
}


// --- БАЛАНС И СЧЕТА ---
if ($action === 'balance') {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/personal/client-info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "Ошибка cURL (Баланс): " . curl_error($ch);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    if ($http_code !== 200) {
        echo "Ошибка Монобанк API! Код: $http_code\n";
        if ($http_code == 403) {
            echo "Причина: Неверный X-Token или слишком частые запросы (лимит: 1 запрос в 60 сек).";
        }
        exit;
    }

    $res = json_decode($response, true);
    if (isset($res['accounts']) && is_array($res['accounts'])) {
        echo "Клиент: " . $res['name'] . "\n";
        
        foreach ($res['accounts'] as $acc) {
            // Берем только основные карты (гривну, доллар или евро)
            if (isset($acc['maskedPan'][0])) {
                $type = "Карта (.." . substr($acc['maskedPan'][0], -4) . ")";
                $bal = number_format($acc['balance'] / 100, 2, '.', '');
                $cur = ($acc['currencyCode'] === 980) ? "UAH" : (($acc['currencyCode'] === 840) ? "USD" : "EUR");
                
                // Выводим структуру, которую ожидает твой Java-парсер
                echo "$type\n$bal $cur\n";
            }
        }
    } else {
        echo "Не удалось прочитать профиль.";
    }
    exit;
}


// --- ИСТОРИЯ ТРАНЗАКЦИЙ ---
if ($action === 'history') {
    // Шаг 1: Запрашиваем client-info, чтобы узнать ID первого счета (аккаунта)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/personal/client-info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $info_response = curl_exec($ch);
    curl_close($ch);

    $info_data = json_decode($info_response, true);
    if (!isset($info_data['accounts'][0]['id'])) {
        echo "Ошибка: Не удалось получить ID счета для истории.";
        exit;
    }
    
    // Берем ID самого первого счета в списке
    $accountId = $info_data['accounts'][0]['id'];
    $time_from = time() - (3 * 24 * 60 * 60); // История за последние 3 дня

    // Шаг 2: Запрашиваем стейтмент (выписку) по этому ID счета
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.monobank.ua/personal/statement/{$accountId}/{$time_from}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "Ошибка cURL (История): " . curl_error($ch);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    if ($http_code !== 200) {
        echo "Ошибка выписки Монобанк. Код: $http_code";
        exit;
    }

    $statements = json_decode($response, true);
    if (!is_array($statements) || empty($statements)) {
        echo "Нет транзакций за последние 3 дня.";
        exit;
    }

    echo "--- ИСТОРИЯ ОПЕРАЦИЙ ---\n";
    foreach ($statements as $item) {
        $amount = $item['amount'] / 100;
        // Ставим знак плюса для зачислений, чтобы Java подсветила зеленым
        $amount_str = ($amount > 0) ? "+" . number_format($amount, 2, '.', '') : number_format($amount, 2, '.', '');
        
        $description = isset($item['description']) ? $item['description'] : 'Операция';
        
        // Передаем построчно: Название операции, затем сумма
        echo $description . "\n";
        echo $amount_str . " UAH\n";
    }
    exit;
}

// Если передан неизвестный action
echo "Ошибка: Неизвестное действие '$action'";
exit;
?>
