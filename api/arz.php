<?php

require_once __DIR__ . '/../App/Utils/pure.php';

header('Content-type: application/json;');

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.bitpin.ir/v1/mkt/currencies/');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36");
$response = json_decode(curl_exec($ch), true)['results'];
curl_close($ch);

foreach ($response as $item) {
    if ($item['title'] == 'Tether') {
        exit(json_encode(['status' => true, 'price' => $item['price_info']['price']], true));
    }
}

?>