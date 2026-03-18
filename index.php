<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

define('SWPM_CREATE_API_URL', 'http://schoolai.biz/wp-json/swpm-ext/v1/member/create');
define('SWPM_CHANGE_LEVEL_API_URL', 'http://schoolai.biz/wp-json/swpm-ext/v1/member/change-level');
define('SWPM_API_KEY', 'a9f2Kx8Qz1mN7rT4vYp3Lw6BcD');

define('PER_PAGE', 25);
function getPdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
function getMembershipLevelLabel(int $level): string
{
    return match ($level) {
        2 => '有効',
        4 => '無効',
        default => (string) $level,
    };
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

function updateMembershipLevel(PDO $pdo, int $userId, int $newLevel, array $apiResult): void
{
    $sql = 'UPDATE users
            SET membership_level = :membership_level,
                api_status = :api_status,
                api_message = :api_message,
                updated_at = NOW()
            WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':membership_level' => $newLevel,
        ':api_status' => !empty($apiResult['success']) ? 'success' : 'error',
        ':api_message' => $apiResult['message'] ?? '',
        ':id' => $userId,
    ]);
}

function sendJson(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function passwordExists(PDO $pdo, string $password): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE password_plain = :password LIMIT 1');
    $stmt->execute([':password' => $password]);

    return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        $pdo = getPdo();

        if ($action === 'check_password') {
            $password = trim((string) ($_POST['password'] ?? ''));
            sendJson([
                'success' => true,
                'exists' => $password === '' ? true : passwordExists($pdo, $password),
            ]);
        }

        if ($action === 'create_member') {
            $form = [
                'email' => trim((string) ($_POST['email'] ?? '')),
                'user_name' => trim((string) ($_POST['user_name'] ?? '')),
                'password' => trim((string) ($_POST['password'] ?? '')),
                'first_name' => trim((string) ($_POST['first_name'] ?? '')),
                'last_name' => trim((string) ($_POST['last_name'] ?? '')),
                'membership_level' => trim((string) ($_POST['membership_level'] ?? '')),
            ];

            if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                sendJson(['success' => false, 'message' => 'メールアドレスが不正です。']);
            }
            if ($form['user_name'] === '') {
                sendJson(['success' => false, 'message' => 'ユーザー名は必須です。']);
            }
            if ($form['password'] === '' || strlen($form['password']) < 8) {
                sendJson(['success' => false, 'message' => 'パスワードは8文字以上で入力してください。']);
            }
            if (!in_array($form['membership_level'], ['2', '4'], true)) {
                sendJson(['success' => false, 'message' => '会員ステータスが不正です。']);
            }

            $apiPayload = [
                'email' => $form['email'],
                'user_name' => $form['user_name'],
                'password' => $form['password'],
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'membership_level' => (int) $form['membership_level'],
                'account_state' => 'active',
            ];

            $apiResult = callSwpmApi(SWPM_CREATE_API_URL, $apiPayload);
            saveUser($pdo, $form, $apiResult);

            sendJson([
                'success' => !empty($apiResult['success']),
                'message' => !empty($apiResult['success']) ? '会員を追加しました。' : 'API実行に失敗しました。',
                'api_message' => $apiResult['message'] ?? '',
            ]);
        }

        if ($action === 'update_level') {
            $userId = (int) ($_POST['member_id'] ?? 0);
            $newLevel = (int) ($_POST['membership_level'] ?? 0);

            if ($userId <= 0 || !in_array((string) $newLevel, ['2', '4'], true)) {
                sendJson(['success' => false, 'message' => '更新パラメータが不正です。']);
            }

            $stmt = $pdo->prepare(
                'SELECT id, email, swpm_member_id, membership_level
                 FROM users
                 WHERE id = :id OR swpm_member_id = :swpm_member_id
                 ORDER BY id = :id_exact DESC
                 LIMIT 1'
            );
            $stmt->execute([
                ':id' => $userId,
                ':swpm_member_id' => $userId,
                ':id_exact' => $userId,
            ]);
            $user = $stmt->fetch();
            if (!$user) {
                sendJson(['success' => false, 'message' => '対象ユーザーが見つかりません。']);
            }

            if ((int) $user['membership_level'] === $newLevel) {
                updateMembershipLevel($pdo, (int) $user['id'], $newLevel, [
                    'success' => true,
                    'message' => '変更なし（DB保存のみ）',
                ]);

                sendJson([
                    'success' => true,
                    'message' => '会員ステータスは変更されていませんが、DB状態を保存しました。',
                    'api_message' => '',
                ]);
            }
            $payload = [
                'new_membership_level' => $newLevel,
            ];
            if (!empty($user['swpm_member_id'])) {
                $payload['member_id'] = (int) $user['swpm_member_id'];
            } else {
                $payload['email'] = (string) $user['email'];
            }
            $apiResult = callSwpmApi(SWPM_CHANGE_LEVEL_API_URL, $payload);
            updateMembershipLevel($pdo, (int) $user['id'], $newLevel, $apiResult);

            sendJson([
                'success' => !empty($apiResult['success']),
                'message' => !empty($apiResult['success']) ? '会員ステータスを更新しました。' : '会員ステータス更新APIに失敗しました。',
                'api_message' => $apiResult['message'] ?? '',
            ]);
        }
        sendJson(['success' => false, 'message' => '不正なアクションです。']);
    } catch (Throwable $e) {
        sendJson(['success' => false, 'message' => 'エラー: ' . $e->getMessage()]);
    }
}
$pdo = getPdo();
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * PER_PAGE;
$search = trim((string) ($_GET['search'] ?? ''));
$searchLike = '%' . $search . '%';

$countSql = 'SELECT COUNT(*) FROM users';
if ($search !== '') {
    $countSql .= ' WHERE first_name LIKE :search OR last_name LIKE :search OR email LIKE :search';
}
$countStmt = $pdo->prepare($countSql);
if ($search !== '') {
    $countStmt->bindValue(':search', $searchLike, PDO::PARAM_STR);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * PER_PAGE;

$listSql = 'SELECT id, swpm_member_id, email, user_name, password_plain, first_name, last_name, membership_level, created_at
            FROM users';
if ($search !== '') {
    $listSql .= ' WHERE first_name LIKE :search OR last_name LIKE :search OR email LIKE :search';
}
$listSql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($listSql);
if ($search !== '') {
    $stmt->bindValue(':search', $searchLike, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', PER_PAGE, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>
<h1>収益コンテンツ登録ユーザ一覧</h1>

<div id="flashMessage" class="flash hidden"></div>

<div class="toolbar">
    <form method="get" class="search-form">
        <input
            type="search"
            name="search"
            placeholder="姓・名・メールアドレスで検索"
            value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
        >
        <button type="submit">検索</button>
    </form>
    <button id="openCreateModalBtn">追加</button>
</div>
<ul class="users-list">
    <?php foreach ($users as $user): ?>
        <li class="user-item">
            <dl class="user-meta">
                <div class="meta_inner"><dt>姓</dt><dd><?php echo htmlspecialchars((string) $user['first_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>名</dt><dd><?php echo htmlspecialchars((string) $user['last_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>LINE名</dt><dd><?php echo htmlspecialchars((string) $user['user_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>メールアドレス</dt><dd><?php echo htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>パスワード</dt><dd><?php echo htmlspecialchars((string) $user['password_plain'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>会員ステータス</dt><dd class="membership-level<?php echo (int) $user['membership_level'] === 4 ? ' is-invalid' : ''; ?>"><?php echo htmlspecialchars(getMembershipLevelLabel((int) $user['membership_level']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div class="meta_inner"><dt>登録日</dt><dd><?php echo htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl>
            <div class="user-actions">
                <button
                    class="editBtn"
                    type="button"
                    data-id="<?php echo (int) $user['id']; ?>"
                    data-email="<?php echo htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-user-name="<?php echo htmlspecialchars((string) $user['user_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-first-name="<?php echo htmlspecialchars((string) $user['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-last-name="<?php echo htmlspecialchars((string) $user['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-membership-level="<?php echo (int) $user['membership_level']; ?>"
                >
                    編集
                </button>
                <button
                    class="copyBtn"
                    type="button"
                    data-email="<?php echo htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-password="<?php echo htmlspecialchars((string) $user['password_plain'], ENT_QUOTES, 'UTF-8'); ?>"
                >
                    コピー
                </button>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<div class="pager">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a
            class="pager-link<?php echo $i === $page ? ' active' : ''; ?>"
            href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
        >
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>
</div>

<?php require __DIR__ . '/input.php'; ?>
<?php require __DIR__ . '/footer.php'; ?>