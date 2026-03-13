<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

/**
 * ===== SWPM API 設定 =====
 * 必要に応じて変更してください
 */
define('SWPM_API_URL', 'http://schoolai.biz/wp-json/swpm-ext/v1/member/create');
define('SWPM_API_KEY', 'a9f2Kx8Qz1mN7rT4vYp3Lw6BcD');

/**
 * ===== DB接続 =====
 */
function getPdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * ===== SWPM API 実行 =====
 */
function callSwpmCreateApi(array $payload): array
{
    $ch = curl_init(SWPM_API_URL);

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-KEY: ' . SWPM_API_KEY,
            'Content-Length: ' . strlen($json),
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

/**
 * ===== usersテーブルへ保存 =====
 * email / user_name の UNIQUE を想定して UPSERT
 */
function saveUser(PDO $pdo, array $formData, array $apiResult): void
{
    $swpmMemberId = null;
    $apiStatus = !empty($apiResult['success']) ? 'success' : 'error';
    $apiMessage = $apiResult['message'] ?? '';
    $accountState = 'active';

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
        ':account_state'    => $accountState,
        ':api_status'       => $apiStatus,
        ':api_message'      => $apiMessage,
    ]);
}

$message = '';
$messageType = '';
$apiResponsePretty = '';

$form = [
    'email' => '',
    'user_name' => '',
    'password' => '',
    'first_name' => '',
    'last_name' => '',
    'membership_level' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['user_name'] = trim((string)($_POST['user_name'] ?? ''));
    $form['password'] = trim((string)($_POST['password'] ?? ''));
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string)($_POST['last_name'] ?? ''));
    $form['membership_level'] = trim((string)($_POST['membership_level'] ?? ''));

    $errors = [];

    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスを正しく入力してください。';
    }

    if ($form['user_name'] === '') {
        $errors[] = 'ユーザー名を入力してください。';
    }

    if ($form['password'] === '' || strlen($form['password']) < 8) {
        $errors[] = 'パスワードは8文字以上で入力してください。';
    }

    if ($form['membership_level'] === '' || !ctype_digit($form['membership_level'])) {
        $errors[] = '会員レベルを数値で入力してください。';
    }

    if (empty($errors)) {
        try {
            $pdo = getPdo();

            $apiPayload = [
                'email' => $form['email'],
                'user_name' => $form['user_name'],
                'password' => $form['password'],
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'membership_level' => (int)$form['membership_level'],
                'account_state' => 'active',
            ];

            $apiResult = callSwpmCreateApi($apiPayload);
            saveUser($pdo, $form, $apiResult);

            $apiResponsePretty = json_encode(
                $apiResult['response'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            if (!empty($apiResult['success'])) {
                $messageType = 'success';
                $message = '会員登録に成功しました。';
                $form['password'] = '';
            } else {
                $messageType = 'error';
                $message = 'API実行は失敗しましたが、履歴は users テーブルに保存しました。';
            }
        } catch (Throwable $e) {
            $messageType = 'error';
            $message = 'エラー: ' . $e->getMessage();
        }
    } else {
        $messageType = 'error';
        $message = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>会員登録ページ</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f7f7f9;
            margin: 0;
            padding: 40px 20px;
            color: #222;
        }
        .wrap {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 24px;
        }
        h1 {
            margin-top: 0;
            font-size: 24px;
        }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .message.success {
            background: #edf9f0;
            border: 1px solid #9fd7ac;
            color: #1d6b31;
        }
        .message.error {
            background: #fff1f1;
            border: 1px solid #e0a6a6;
            color: #a12b2b;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
        }
        button {
            border: none;
            background: #111;
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            opacity: .9;
        }
        .api-box {
            margin-top: 24px;
            padding: 16px;
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        pre {
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
        }
        .note {
            margin-top: 16px;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Simple Membership 会員登録</h1>

    <?php if ($message !== ''): ?>
        <div class="message <?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <div class="form-group">
            <label for="email">メールアドレス</label>
            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="user_name">ユーザー名</label>
            <input type="text" name="user_name" id="user_name" value="<?php echo htmlspecialchars($form['user_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="password">パスワード</label>
            <input type="text" name="password" id="password" value="<?php echo htmlspecialchars($form['password'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="first_name">姓</label>
            <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($form['first_name'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label for="last_name">名</label>
            <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($form['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label for="membership_level">会員レベル</label>
            <input type="number" name="membership_level" id="membership_level" value="<?php echo htmlspecialchars($form['membership_level'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <button type="submit">登録する</button>
    </form>

    <?php if ($apiResponsePretty !== ''): ?>
        <div class="api-box">
            <strong>APIレスポンス</strong>
            <pre><?php echo htmlspecialchars($apiResponsePretty, ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    <?php endif; ?>

    <div class="note">
        ※ 現状は users テーブルに password_plain を保存する前提です。<br>
        ※ 本番運用では平文パスワード保存は避けるのがおすすめです。
    </div>
</div>
</body>
</html>