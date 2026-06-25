<?php
ini_set('display_errors', 0); // Выключаем сырой вывод ошибок, чтобы не ломать Java-парсер
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) J2ME-Proxy';
$action = isset($_GET['action']) ? trim($_GET['action']) : 'balance';

// Достаем X-Token из заголовков запроса
$headers = getallheaders();
$token = '';
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-token') {
        $token = trim($value);
        break;
    }
}

if (empty($token)) {
    echo "MAIN_PAN: Ошибка авторизации\n";
    echo "MAIN_BAL: Нет токена\n";
    echo "ROW:Системная ошибка|Укажите X-Token в настройках|RED\n";
    exit;
}

// --- ВКЛАДКА: КУРСЫ ВАЛЮТ ---
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
        echo "MAIN_PAN: Курсы валют\nMAIN_BAL: Ошибка\nROW:Сервер Mono|Вернул код $http_code|RED\n";
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        echo "MAIN_PAN: Курсы валют\nMAIN_BAL: Ошибка\nROW:Ошибка JSON|Не удалось распарсить|RED\n";
        exit;
    }

    echo "MAIN_PAN: Курсы валют\n";
    echo "MAIN_BAL: Обмен валют\n";
    
    foreach ($data as $c) {
        if (($c['currencyCodeA'] == 840 || $c['currencyCodeA'] == 978) && $c['currencyCodeB'] == 980) {
            $name = ($c['currencyCodeA'] == 840) ? "Доллар США (USD)" : "Евро (EUR)";
            $buy = number_format($c['rateBuy'], 2, '.', '');
            $sell = number_format($c['rateSell'], 2, '.', '');
            echo "ROW:{$name}|{$buy} / {$sell}|NORMAL\n";
        }
    }
    exit;
}

// --- ВКЛАДКА: ИСТОРИЯ ОПЕРАЦИЙ ---
if ($action === 'history') {
    // Сначала запрашиваем ID аккаунта
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.monobank.ua/personal/client-info');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $info_res = curl_exec($ch);
    curl_close($ch);

    $info_data = json_decode($info_res, true);
    if (!isset($info_data['accounts'][0]['id'])) {
        echo "MAIN_PAN: История выписок\nMAIN_BAL: Ошибка\nROW:Аккаунт|Не удалось получить ID|RED\n";
        exit;
    }

    $accountId = $info_data['accounts'][0]['id'];
    $time_from = time() - (3 * 24 * 60 * 60); // Последние 3 дня

    // Запрашиваем историю
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.monobank.ua/personal/statement/{$accountId}/{$time_from}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo "MAIN_PAN: История выписок\nMAIN_BAL: Ошибка\nROW:Ошибка API|Код ответа сервера: $http_code|RED\n";
        exit;
    }

    $statements = json_decode($response, true);
    echo "MAIN_PAN: История транзакций\n";
    echo "MAIN_BAL: Выписка за 3 дня\n";

    if (empty($statements) || !is_array($statements)) {
        echo "ROW:Транзакции|Нет операций за 3 дня|NORMAL\n";
        exit;
    }

    foreach ($statements as $item) {
        $amount = $item['amount'] / 100;
        $desc = isset($item['description']) ? trim($item['description']) : 'Операция';
        
        if ($amount > 0) {
            $amount_str = "+" . number_format($amount, 2, '.', '') . " ₴";
            $type = "GREEN";
        } else {
            $amount_str = number_format($amount, 2, '.', '') . " ₴";
            $type = "RED";
        }
        echo "ROW:{$desc}|{$amount_str}|{$type}\n";
    }
    exit;
}

// --- ВКЛАДКА: ГЛАВНАЯ (БАЛАНС И СЧЕТА) ---
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
        echo "MAIN_PAN: Ошибка подключения\nMAIN_BAL: Код $http_code\n";
        if ($http_code == 403) {
            echo "ROW:Лимит запросов|Допускается 1 запрос в 60 сек|RED\n";
        } else {
            echo "ROW:Сбой авторизации|Проверьте правильность токена|RED\n";
        }
        exit;
    }

    $res = json_decode($response, true);
    if (isset($res['accounts']) && is_array($res['accounts'])) {
        $mainPan = "";
        $mainBal = "";
        $hasMain = false;
        $rowsData = array();

        foreach ($res['accounts'] as $acc) {
            $bal = number_format($acc['balance'] / 100, 2, '.', '');
            $cur = ($acc['currencyCode'] === 980) ? "₴" : (($acc['currencyCode'] === 840) ? "$" : "€");
            
            if (isset($acc['maskedPan'][0])) {
                $pan = "Карта (.." . substr($acc['maskedPan'][0], -4) . ")";
                if (!$hasMain) {
                    $mainPan = $pan;
                    $mainBal = "$bal $cur";
                    $hasMain = true;
                }
                $rowsData[] = "ROW:{$pan}|{$bal} {$cur}|NORMAL";
            } else {
                $type = isset($acc['type']) ? ucfirst($acc['type']) : 'Счет';
                $rowsData[] = "ROW:{$type}|{$bal} {$cur}|NORMAL";
            }
        }

        echo "MAIN_PAN: " . (empty($mainPan) ? "Основной счет" : $mainPan) . "\n";
        echo "MAIN_BAL: " . (empty($mainBal) ? "0.00 ₴" : $mainBal) . "\n";
        echo "ROW:Владелец счета|{$res['name']}|NORMAL\n";
        foreach ($rowsData as $row) {
            echo $row . "\n";
        }
    } else {
        echo "MAIN_PAN: Ошибка профиля\nMAIN_BAL: JSON Error\nROW:Данные|Не удалось собрать профиль|RED\n";
    }
    exit;
}
?>
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
