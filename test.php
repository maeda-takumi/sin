<?php

$url = 'http://schoolai.biz/wp-json/swpm-ext/v1/member/create';
$apiKey = 'a9f2Kx8Qz1mN7rT4vYp3Lw6BcD';

$data = [
    'email' => 'test001@example.com',
    'user_name' => 'test001',
    'password' => 'TestPass123!',
    'first_name' => 'Taro',
    'last_name' => 'Yamada',
    'membership_level' => 2,
    'account_state' => 'active',
];

$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-KEY: ' . $apiKey,
        'Content-Length: ' . strlen($payload),
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

header('Content-Type: text/plain; charset=UTF-8');

echo "HTTP CODE: " . $httpCode . "\n\n";

if ($curlError) {
    echo "CURL ERROR:\n";
    echo $curlError . "\n\n";
}

echo "REQUEST JSON:\n";
echo $payload . "\n\n";

echo "RESPONSE:\n";
echo $response . "\n";