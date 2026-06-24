// Включаем отображение всех ошибок PHP для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

// Логируем входящий запрос для отладки (можно посмотреть в файле request.log)
$debug_info = "--- ЗАПРОС " . date('Y-m-d H:i:s') . " ---\n";
$debug_info .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$debug_info .= "Query: " . $_SERVER['QUERY_STRING'] . "\n";

$headers = getallheaders();
foreach ($headers as $key => $value) {
    $debug_info .= "Header $key: $value\n";
}
file_put_contents('    $ch = curl_init();
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
        echo "Ошибка: Не удалось распарсить JSON валют.\nОтвет: " . substr($response, 0, 100);
        exit;
    }

    echo "--- КУРСЫ ВАЛЮТ ---\n";
    foreach ($data as $c) {
        if ($c['currencyCodeA'] == 840 && $c['currencyCodeB'] == 980) {
            echo "USD: " . $c['rateBuy'] . " / " . $c['rateSell'] . "\n";
        }
        if ($c['currencyCodeA'] == 978 && $c['currencyCodeB'] == 980) {
            echo "EUR: " . $c['rateBuy'] . " / " . $c['rateSell'] . "\n";
        }
    }
    exit;
}

// Для приватных методов нужен токен
if (!$token) {
    echo "Ошибка: Кнопка требует X-Token, но телефон его не передал!";
    exit;
}

// --- 2. БАЛАНС И СЧЕТА ---
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
        echo "Ошибка Монобанк API!\nКод ответа: $http_code\n";
        if ($http_code == 403) echo "Причина: Неверный токен (X-Token) или лимит запросов (1 запрос в 60 сек для баланса).";
        else echo "Ответ: " . $response;
        exit;
    }

    $res = json_decode($response, true);
    if (isset($res['accounts'])) {
        echo "Клиент: " . $res['name'] . "\n";
        echo "--------------------\n";
        foreach ($res['accounts'] as $acc) {
            $type = isset($acc['maskedPan'][0]) ? "Карта (.." . substr($acc['maskedPan'][0], -4) . ")" : "Счет";
            $bal = number_format($acc['balance'] / 100, 2, '.', '');
            $cur = ($acc['currencyCode'] === 980) ? "UAH" : (($acc['currencyCode'] === 840) ? "USD" : "EUR");
            echo "$type:\n$bal $cur\n\n";
        }
    } else {
        echo "Не удалось прочитать профиль.";
    }
    exit;
}

// --- 3. ИСТОРИЯ ТРАНЗАКЦИЙ ---
if ($action === 'history') {
    $time_from = time() - (3 * 24 * 60 * 60); // 3 дня
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.monobank.ua/personal/statement/0/$time_from");
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
        echo "Ошибка Истории: $http_code\n" . $response;
        exit;
    }

    $res = json_decode($response, true);
    if (is_array($res) && !empty($res)) {
        echo "--- ВЫПИСКА ---\n";
        $count = 0;
        foreach ($res as $tx) {
            if ($count++ >= 5) break;
            $amount = number_format($tx['amount'] / 100, 2, '.', '');
            $desc = $tx['description'];
            echo ($amount > 0 ? "+" : "") . "$amount UAH\n$desc\n\n";
        }
    } else {
        echo "Транзакций за 3 дня не найдено или слишком частые запросы.";
    }
    exit;
}
?>
