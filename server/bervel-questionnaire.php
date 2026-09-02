<?php
/**
 * BERVEL questionnaire API.
 *
 * Canonical source: MOX-Studio/bervel/server/bervel-questionnaire.php
 * Deployed DEV copy: MOX-Studio/eco-standard-bitrix/ajax/bervel-questionnaire.php
 * Runtime config and the SQLite database stay outside the public document root.
 */

declare(strict_types=1);

const BERVEL_SCHEMA_VERSION = 'bervel-questionnaire/v1';
const BERVEL_MAX_BODY_BYTES = 262144;
const BERVEL_MAX_ANSWER_CHARS = 12000;

$questionTitles = [
    'C01' => 'Задачи первой версии',
    'C02' => 'Роли пользователей',
    'C03' => 'Одновременные пользователи',
    'C04' => 'Основной источник',
    'C05' => 'Доступ к источнику',
    'C06' => 'Подходящий тендер',
    'C07' => 'Причины отказа',
    'C08' => 'Обработка тендера',
    'C09' => 'Решение сотрудника',
    'C14' => 'Данные в 1С',
    'C15' => 'Результаты тендеров в CRM',
    'C17' => 'Экономика и мощности',
    'C18' => 'Характеристики продукции',
    'C19' => 'Формат результата',
    'C20' => 'Бланки и оформление',
    'C21' => 'Рабочее место',
    'C22' => 'Главные экраны',
    'C23' => 'Настройки системы',
    'C24' => 'Объём и скорость',
    'C25' => 'Размещение системы',
    'C26' => 'Выбор нейросети',
    'C27' => 'Подключение нейросети',
    'C29' => 'Успех пилота',
];

$prefilledQuestionIds = [
    'C01', 'C02', 'C04', 'C06', 'C07', 'C08', 'C09', 'C14', 'C15',
    'C17', 'C18', 'C19', 'C20', 'C21', 'C22', 'C24', 'C25',
];

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(int $status, string $code, string $message, array $details = []): void
{
    $error = ['code' => $code, 'message' => $message];
    if ($details) {
        $error['details'] = $details;
    }
    jsonResponse($status, ['ok' => false, 'error' => $error]);
}

function stringLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function bearerToken(): string
{
    $authorization = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $authorization = (string) $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $authorization = (string) $headers['authorization'];
        }
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
        return '';
    }

    return trim($matches[1]);
}

function loadConfig(): array
{
    $configPath = getenv('BERVEL_CONFIG_PATH');
    if (!$configPath) {
        $configPath = dirname(dirname(__DIR__)) . '/.bervel-questionnaire/config.php';
    }

    if (!is_readable($configPath)) {
        apiError(503, 'service_not_configured', 'Сервис временно не настроен.');
    }

    $config = require $configPath;
    if (!is_array($config)) {
        apiError(503, 'invalid_service_config', 'Конфигурация сервиса недоступна.');
    }

    $required = ['database_path', 'admin_token_hash', 'allowed_origins'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $config)) {
            apiError(503, 'invalid_service_config', 'Конфигурация сервиса неполная.');
        }
    }

    if (!is_string($config['database_path']) || $config['database_path'] === '') {
        apiError(503, 'invalid_service_config', 'Путь к базе данных не настроен.');
    }
    if (!is_string($config['admin_token_hash']) || !preg_match('/^[a-f0-9]{64}$/', $config['admin_token_hash'])) {
        apiError(503, 'invalid_service_config', 'Доступ к панели не настроен.');
    }
    if (!is_array($config['allowed_origins'])) {
        apiError(503, 'invalid_service_config', 'Список разрешённых сайтов не настроен.');
    }

    return $config;
}

function openDatabase(string $databasePath): PDO
{
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        apiError(503, 'database_unavailable', 'Не удалось подготовить хранилище ответов.');
    }
    @chmod($directory, 0700);

    try {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS bervel_questionnaire_submissions (' .
            'id TEXT PRIMARY KEY,' .
            'created_at TEXT NOT NULL,' .
            'questionnaire_version TEXT NOT NULL,' .
            'answer_count INTEGER NOT NULL,' .
            'answers_json TEXT NOT NULL,' .
            'payload_sha256 TEXT NOT NULL,' .
            'request_origin TEXT NOT NULL DEFAULT ""' .
            ')'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_bervel_submissions_created_at ' .
            'ON bervel_questionnaire_submissions(created_at DESC)'
        );
        @chmod($databasePath, 0600);
        return $pdo;
    } catch (Throwable $error) {
        error_log('BERVEL questionnaire database error: ' . $error->getMessage());
        apiError(503, 'database_unavailable', 'Хранилище ответов временно недоступно.');
    }
}

$config = loadConfig();
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
$allowedOrigins = array_values(array_filter($config['allowed_origins'], 'is_string'));
$originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

header('Vary: Origin');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

if ($method === 'OPTIONS') {
    if (!$originAllowed) {
        apiError(403, 'origin_not_allowed', 'Этот сайт не может обращаться к сервису.');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

if ($origin !== '' && !$originAllowed) {
    apiError(403, 'origin_not_allowed', 'Этот сайт не может обращаться к сервису.');
}

if ($method === 'POST') {
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > BERVEL_MAX_BODY_BYTES) {
        apiError(413, 'payload_too_large', 'Ответы превышают допустимый размер.');
    }

    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
    if (strpos($contentType, 'application/json') !== 0) {
        apiError(415, 'unsupported_media_type', 'Ответы нужно отправлять в формате JSON.');
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > BERVEL_MAX_BODY_BYTES) {
        apiError(413, 'payload_too_large', 'Ответы превышают допустимый размер.');
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        apiError(400, 'invalid_json', 'Не удалось прочитать отправленные ответы.');
    }

    if (isset($payload['website']) && trim((string) $payload['website']) !== '') {
        apiError(400, 'invalid_submission', 'Не удалось принять ответы.');
    }
    if (!isset($payload['schemaVersion']) || $payload['schemaVersion'] !== BERVEL_SCHEMA_VERSION) {
        apiError(422, 'invalid_schema_version', 'Версия формы не поддерживается. Обновите страницу.');
    }

    $submissionId = isset($payload['submissionId']) ? trim((string) $payload['submissionId']) : '';
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submissionId)) {
        apiError(422, 'invalid_submission_id', 'Некорректный идентификатор отправки.');
    }

    $questionnaireVersion = isset($payload['questionnaireVersion'])
        ? trim((string) $payload['questionnaireVersion'])
        : '';
    if ($questionnaireVersion === '' || stringLength($questionnaireVersion) > 32) {
        apiError(422, 'invalid_questionnaire_version', 'Версия опросника не указана.');
    }

    if (!isset($payload['answers']) || !is_array($payload['answers'])) {
        apiError(422, 'answers_required', 'Необходимо заполнить все ответы.');
    }

    $answersById = [];
    foreach ($payload['answers'] as $answerRow) {
        if (!is_array($answerRow)) {
            apiError(422, 'invalid_answer', 'Один из ответов имеет неверный формат.');
        }
        $id = isset($answerRow['id']) ? trim((string) $answerRow['id']) : '';
        if (!isset($questionTitles[$id]) || isset($answersById[$id])) {
            apiError(422, 'invalid_answer_id', 'Список вопросов не совпадает с текущей формой.');
        }
        $answer = isset($answerRow['answer']) ? trim((string) $answerRow['answer']) : '';
        if ($answer === '') {
            apiError(422, 'answer_required', 'Необходимо заполнить каждый ответ.', ['questionId' => $id]);
        }
        if (stringLength($answer) > BERVEL_MAX_ANSWER_CHARS) {
            apiError(422, 'answer_too_long', 'Один из ответов слишком длинный.', ['questionId' => $id]);
        }
        $confirmed = isset($answerRow['confirmed']) && $answerRow['confirmed'] === true;
        if (in_array($id, $prefilledQuestionIds, true) && !$confirmed) {
            apiError(422, 'confirmation_required', 'Подтвердите предзаполненный ответ.', ['questionId' => $id]);
        }
        $answersById[$id] = [
            'id' => $id,
            'question' => $questionTitles[$id],
            'answer' => $answer,
            'confirmed' => $confirmed,
        ];
    }

    if (count($answersById) !== count($questionTitles)) {
        $missing = array_values(array_diff(array_keys($questionTitles), array_keys($answersById)));
        apiError(422, 'answers_incomplete', 'Необходимо заполнить все ответы.', ['missingQuestionIds' => $missing]);
    }

    $normalizedAnswers = [];
    foreach (array_keys($questionTitles) as $id) {
        $normalizedAnswers[] = $answersById[$id];
    }
    $answersJson = json_encode($normalizedAnswers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($answersJson === false) {
        apiError(422, 'invalid_answers_encoding', 'Не удалось подготовить ответы к сохранению.');
    }
    $payloadHash = hash('sha256', $questionnaireVersion . "\n" . $answersJson);
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');
    $pdo = openDatabase($config['database_path']);

    try {
        $pdo->beginTransaction();
        $existing = $pdo->prepare(
            'SELECT id, created_at, payload_sha256, answer_count ' .
            'FROM bervel_questionnaire_submissions WHERE id = :id'
        );
        $existing->execute([':id' => $submissionId]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            if (!hash_equals((string) $existingRow['payload_sha256'], $payloadHash)) {
                $pdo->rollBack();
                apiError(409, 'submission_id_conflict', 'Эта отправка уже сохранена с другим содержимым.');
            }
            $pdo->commit();
            jsonResponse(200, [
                'ok' => true,
                'data' => [
                    'id' => (string) $existingRow['id'],
                    'createdAt' => (string) $existingRow['created_at'],
                    'answerCount' => (int) $existingRow['answer_count'],
                    'duplicate' => true,
                ],
            ]);
        }

        $insert = $pdo->prepare(
            'INSERT INTO bervel_questionnaire_submissions ' .
            '(id, created_at, questionnaire_version, answer_count, answers_json, payload_sha256, request_origin) ' .
            'VALUES (:id, :created_at, :questionnaire_version, :answer_count, :answers_json, :payload_sha256, :request_origin)'
        );
        $insert->execute([
            ':id' => $submissionId,
            ':created_at' => $createdAt,
            ':questionnaire_version' => $questionnaireVersion,
            ':answer_count' => count($normalizedAnswers),
            ':answers_json' => $answersJson,
            ':payload_sha256' => $payloadHash,
            ':request_origin' => $origin,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('BERVEL questionnaire insert error: ' . $error->getMessage());
        apiError(503, 'database_write_failed', 'Не удалось сохранить ответы. Попробуйте ещё раз.');
    }

    jsonResponse(201, [
        'ok' => true,
        'data' => [
            'id' => $submissionId,
            'createdAt' => $createdAt,
            'answerCount' => count($normalizedAnswers),
            'duplicate' => false,
        ],
    ]);
}

if ($method === 'GET') {
    $token = bearerToken();
    if ($token === '' || !hash_equals($config['admin_token_hash'], hash('sha256', $token))) {
        header('WWW-Authenticate: Bearer realm="BERVEL answers"');
        apiError(401, 'unauthorized', 'Неверный ключ доступа.');
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    $limit = max(1, min(100, $limit));
    $pdo = openDatabase($config['database_path']);

    try {
        $query = $pdo->prepare(
            'SELECT id, created_at, questionnaire_version, answer_count, answers_json ' .
            'FROM bervel_questionnaire_submissions ORDER BY created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();
        $submissions = [];
        while ($row = $query->fetch()) {
            $answers = json_decode((string) $row['answers_json'], true);
            if (!is_array($answers)) {
                $answers = [];
            }
            $submissions[] = [
                'id' => (string) $row['id'],
                'createdAt' => (string) $row['created_at'],
                'questionnaireVersion' => (string) $row['questionnaire_version'],
                'answerCount' => (int) $row['answer_count'],
                'answers' => $answers,
            ];
        }
    } catch (Throwable $error) {
        error_log('BERVEL questionnaire read error: ' . $error->getMessage());
        apiError(503, 'database_read_failed', 'Не удалось загрузить ответы.');
    }

    jsonResponse(200, [
        'ok' => true,
        'data' => [
            'submissions' => $submissions,
            'count' => count($submissions),
        ],
    ]);
}

header('Allow: GET, POST, OPTIONS');
apiError(405, 'method_not_allowed', 'Метод не поддерживается.');
