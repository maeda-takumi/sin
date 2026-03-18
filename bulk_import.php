<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

define('SWPM_CREATE_API_URL', 'http://schoolai.biz/wp-json/swpm-ext/v1/member/create');
define('SWPM_API_KEY', 'a9f2Kx8Qz1mN7rT4vYp3Lw6BcD');

function getPdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function callSwpmApi(string $url, array $payload): array
{
    $ch = curl_init($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-KEY: ' . SWPM_API_KEY,
            'Content-Length: ' . strlen((string) $json),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlError !== '') {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'cURL Error: ' . $curlError,
            'response' => null,
        ];
    }

    $decoded = json_decode((string) $responseBody, true);

    if (!is_array($decoded)) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'API response is not valid JSON',
            'response' => $responseBody,
        ];
    }

    return [
        'success' => !empty($decoded['success']),
        'http_code' => $httpCode,
        'message' => $decoded['message'] ?? 'Unknown response',
        'response' => $decoded,
    ];
}

function saveUser(PDO $pdo, array $formData, array $apiResult): void
{
    $swpmMemberId = null;

    if (!empty($apiResult['response']['data']['member_id'])) {
        $swpmMemberId = (int) $apiResult['response']['data']['member_id'];
    }

    $sql = "
        INSERT INTO users (
            swpm_member_id,
            email,
            user_name,
            password_plain,
            first_name,
            last_name,
            membership_level,
            account_state,
            api_status,
            api_message,
            created_at,
            updated_at
        ) VALUES (
            :swpm_member_id,
            :email,
            :user_name,
            :password_plain,
            :first_name,
            :last_name,
            :membership_level,
            :account_state,
            :api_status,
            :api_message,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            swpm_member_id = VALUES(swpm_member_id),
            password_plain = VALUES(password_plain),
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            membership_level = VALUES(membership_level),
            account_state = VALUES(account_state),
            api_status = VALUES(api_status),
            api_message = VALUES(api_message),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':swpm_member_id'   => $swpmMemberId,
        ':email'            => $formData['email'],
        ':user_name'        => $formData['user_name'],
        ':password_plain'   => $formData['password'],
        ':first_name'       => $formData['first_name'],
        ':last_name'        => $formData['last_name'],
        ':membership_level' => (int) $formData['membership_level'],
        ':account_state'    => 'active',
        ':api_status'       => !empty($apiResult['success']) ? 'success' : 'error',
        ':api_message'      => $apiResult['message'] ?? '',
    ]);
}

function passwordExists(PDO $pdo, string $password): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE password_plain = :password LIMIT 1');
    $stmt->execute([':password' => $password]);

    return (bool) $stmt->fetchColumn();
}

function emailExists(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);

    return (bool) $stmt->fetchColumn();
}

function generateUniquePassword(PDO $pdo, int $length = 12): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
    $max = strlen($chars) - 1;

    do {
        $password = '';
        for ($i = 0; $i < $length; $i += 1) {
            $password .= $chars[random_int(0, $max)];
        }
    } while (passwordExists($pdo, $password));

    return $password;
}

function detectDelimiter(string $line): string
{
    $tabCount = substr_count($line, "\t");
    $commaCount = substr_count($line, ',');

    return $tabCount >= $commaCount ? "\t" : ',';
}

function normalizeHeader(string $value): string
{
    return preg_replace('/\s+/u', '', trim($value)) ?? '';
}

function writeError(string $message): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, $message);
        return;
    }

    file_put_contents('php://stderr', $message);
}

function isHttpUrl(string $value): bool
{
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

function openCsvHandle(string $pathOrUrl)
{
    if (isHttpUrl($pathOrUrl)) {
        $ch = curl_init($pathOrUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            writeError("URL取得に失敗しました: {$curlError}\n");
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            writeError("URL取得に失敗しました: HTTP {$httpCode}\n");
            return false;
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            writeError("一時ストリームを作成できませんでした。\n");
            return false;
        }

        fwrite($handle, (string) $body);
        rewind($handle);

        return $handle;
    }

    if (!is_readable($pathOrUrl)) {
        writeError("ファイルを読めません: {$pathOrUrl}\n");
        return false;
    }

    $handle = fopen($pathOrUrl, 'r');
    if ($handle === false) {
        writeError("ファイルを開けませんでした。\n");
        return false;
    }

    return $handle;
}
$isCli = PHP_SAPI === 'cli';
$filePath = __DIR__ . '/list.csv';
$membershipLevel = '2';
$dryRun = false;

if ($isCli) {
    $argv = $_SERVER['argv'] ?? [];

    foreach (array_slice(is_array($argv) ? $argv : [], 1) as $option) {
        if (str_starts_with($option, '--membership=')) {
            $membershipLevel = substr($option, strlen('--membership='));
        }
        if ($option === '--dry-run') {
            $dryRun = true;
        }
    }
} else {
    header('Content-Type: text/plain; charset=UTF-8');

    if (isset($_GET['membership'])) {
        $membershipLevel = (string) $_GET['membership'];
    }

    $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
}


if (!in_array($membershipLevel, ['2', '4'], true)) {
    writeError("--membership は 2 または 4 を指定してください。\n");
    exit(1);
}

$pdo = getPdo();
$handle = openCsvHandle($filePath);
if ($handle === false) {
    exit(1);
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    fclose($handle);
    writeError("ファイルが空です。\n");
    exit(1);
}

$delimiter = detectDelimiter($firstLine);
rewind($handle);

$header = fgetcsv($handle, 0, $delimiter);
if ($header === false) {
    fclose($handle);
    writeError("ヘッダー行を読めませんでした。\n");
    exit(1);
}

$headerMap = [];
foreach ($header as $idx => $title) {
    $headerMap[normalizeHeader((string) $title)] = $idx;
}

$requiredHeaders = ['LINE名', 'メールアドレス', '姓', '名'];
foreach ($requiredHeaders as $requiredHeader) {
    if (!array_key_exists(normalizeHeader($requiredHeader), $headerMap)) {
        fclose($handle);
        writeError("必須ヘッダー不足: {$requiredHeader}\n");
        exit(1);
    }
}

$seenInFile = [];
$lineNo = 1;
$success = 0;
$failed = 0;
$skipped = 0;

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNo += 1;

    $email = trim((string) ($row[$headerMap[normalizeHeader('メールアドレス')]] ?? ''));
    $userName = trim((string) ($row[$headerMap[normalizeHeader('LINE名')]] ?? ''));
    $firstName = trim((string) ($row[$headerMap[normalizeHeader('姓')]] ?? ''));
    $lastName = trim((string) ($row[$headerMap[normalizeHeader('名')]] ?? ''));

    if ($email === '' && $userName === '' && $firstName === '' && $lastName === '') {
        continue;
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failed += 1;
        echo "[{$lineNo}] 失敗: メールアドレス不正 ({$email})\n";
        continue;
    }

    if ($userName === '') {
        $failed += 1;
        echo "[{$lineNo}] 失敗: LINE名が空です。\n";
        continue;
    }

    if (isset($seenInFile[$email])) {
        $skipped += 1;
        echo "[{$lineNo}] スキップ: CSV内でメール重複 ({$email})\n";
        continue;
    }
    $seenInFile[$email] = true;

    if (emailExists($pdo, $email)) {
        $skipped += 1;
        echo "[{$lineNo}] スキップ: 既存メール ({$email})\n";
        continue;
    }

    $password = generateUniquePassword($pdo);

    $form = [
        'email' => $email,
        'user_name' => $userName,
        'password' => $password,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'membership_level' => $membershipLevel,
    ];

    if ($dryRun) {
        $success += 1;
        echo "[{$lineNo}] DRY-RUN: 登録予定 {$email} ({$userName})\n";
        continue;
    }

    $payload = [
        'email' => $form['email'],
        'user_name' => $form['user_name'],
        'password' => $form['password'],
        'first_name' => $form['first_name'],
        'last_name' => $form['last_name'],
        'membership_level' => (int) $form['membership_level'],
        'account_state' => 'active',
    ];

    $apiResult = callSwpmApi(SWPM_CREATE_API_URL, $payload);
    saveUser($pdo, $form, $apiResult);

    if (!empty($apiResult['success'])) {
        $success += 1;
        echo "[{$lineNo}] 成功: {$email}\n";
    } else {
        $failed += 1;
        $message = (string) ($apiResult['message'] ?? 'unknown error');
        echo "[{$lineNo}] 失敗: {$email} / {$message}\n";
    }
}

fclose($handle);

echo "\n--- 取込結果 ---\n";
echo "成功: {$success}\n";
echo "失敗: {$failed}\n";
echo "スキップ: {$skipped}\n";
