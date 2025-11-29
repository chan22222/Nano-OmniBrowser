<?php
/**
 * Gemini 이미지 생성 API 프록시
 * dothome 웹호스팅용
 *
 * 기능:
 * - 10개 API 키 로테이션
 * - 자동 재시도 (오버로드, 타임아웃, 네트워크 에러 시)
 * - 실패한 키 제외하고 다른 키로 재시도
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Fatal error handler to ensure JSON response
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => '서버 오류가 발생했습니다: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

require_once 'config.php';

// CORS 헤더 설정
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 세션 디렉토리 생성
if (!file_exists(SESSION_DIR)) {
    @mkdir(SESSION_DIR, 0755, true);
}

/**
 * 재시도 가능한 에러인지 확인
 */
function isRetryableError($httpCode, $errorMsg, $curlErrno) {
    // cURL 에러: 타임아웃, 연결 실패 등
    if ($curlErrno > 0) {
        return true;
    }

    // HTTP 상태 코드 기반
    if (in_array($httpCode, [429, 500, 502, 503, 504])) {
        return true;
    }

    // 에러 메시지 기반
    $retryableMessages = [
        'overloaded',
        'rate limit',
        'quota',
        'capacity',
        'timeout',
        'temporarily',
        'try again',
        'too many requests',
        'resource exhausted',
        'internal error',
        'service unavailable'
    ];

    $errorLower = strtolower($errorMsg);
    foreach ($retryableMessages as $msg) {
        if (strpos($errorLower, $msg) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Keep-alive 출력 (nginx 타임아웃 우회)
 */
function sendKeepAlive() {
    static $count = 0;
    $count++;
    // 공백 출력으로 연결 유지 (JSON 파싱에 영향 없음)
    echo " ";
    if (ob_get_level()) ob_flush();
    flush();
}

/**
 * Gemini API 호출 (단일 시도)
 */
function callGeminiAPISingle($model, $contents, $generationConfig = [], $apiKey = null) {
    if (!$apiKey) {
        $apiKey = GEMINI_API_KEY;
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $requestBody = [
        'contents' => $contents
    ];

    // generationConfig 추가 (responseModalities 등)
    if (!empty($generationConfig)) {
        $requestBody['generationConfig'] = $generationConfig;
    }

    // 응답 저장용
    $responseData = '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        // Keep-alive: 진행 콜백으로 주기적 출력
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) {
            static $lastTime = 0;
            $now = time();
            // 10초마다 keep-alive 전송
            if ($now - $lastTime >= 10) {
                sendKeepAlive();
                $lastTime = $now;
            }
            return 0; // 0 = 계속 진행
        }
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'httpCode' => $httpCode,
        'curlError' => $error,
        'curlErrno' => $errno,
        'apiKey' => $apiKey
    ];
}

/**
 * Gemini API 호출 (재시도 로직 포함)
 */
function callGeminiAPI($model, $contents, $generationConfig = [], $extraConfig = []) {
    $allKeys = getAllApiKeys();
    $usedKeys = [];
    $lastError = '';
    $lastHttpCode = 0;

    // 첫 번째 시도는 순차 선택된 키 사용
    $currentKey = GEMINI_API_KEY;

    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        // 디버그 로그
        $keyPreview = substr($currentKey, 0, 15) . '...';
        error_log("Gemini API 시도 #{$attempt}: 키={$keyPreview}");

        $result = callGeminiAPISingle($model, $contents, $generationConfig, $currentKey);

        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $curlError = $result['curlError'];
        $curlErrno = $result['curlErrno'];

        // cURL 에러 처리
        if ($curlErrno > 0) {
            $usedKeys[] = $currentKey;
            $lastError = $curlError;
            $lastHttpCode = 0;

            error_log("Gemini API cURL Error #{$attempt}: [{$curlErrno}] {$curlError}");

            if ($attempt < MAX_RETRIES - 1) {
                // 다른 키로 재시도
                $currentKey = getAlternativeApiKey($usedKeys);
                usleep(RETRY_DELAY_MS * 1000); // 밀리초를 마이크로초로 변환
                continue;
            }

            if ($curlErrno == 28) {
                return ['error' => 'API 요청 시간 초과. 잠시 후 다시 시도해주세요. (재시도 ' . ($attempt + 1) . '회 실패)'];
            }
            return ['error' => 'API 연결 실패: ' . $curlError . ' (재시도 ' . ($attempt + 1) . '회 실패)'];
        }

        $data = json_decode($response, true);

        // HTTP 에러 처리
        if ($httpCode !== 200) {
            $errorMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            $errorDetails = isset($data['error']['details']) ? json_encode($data['error']['details']) : '';

            error_log("Gemini API Error #{$attempt} [{$httpCode}]: {$errorMsg}");

            // 재시도 가능한 에러인지 확인
            if (isRetryableError($httpCode, $errorMsg, 0) && $attempt < MAX_RETRIES - 1) {
                $usedKeys[] = $currentKey;
                $lastError = $errorMsg;
                $lastHttpCode = $httpCode;

                // 다른 키로 재시도
                $currentKey = getAlternativeApiKey($usedKeys);
                usleep(RETRY_DELAY_MS * 1000);
                continue;
            }

            return [
                'error' => "API 오류 ({$httpCode}): {$errorMsg}",
                'details' => $errorDetails,
                'model' => isset($GLOBALS['debugModel']) ? $GLOBALS['debugModel'] : '',
                'retries' => $attempt + 1
            ];
        }

        // JSON 파싱 실패
        if (!$data) {
            error_log("Gemini API: Invalid JSON response on attempt #{$attempt}");

            if ($attempt < MAX_RETRIES - 1) {
                $usedKeys[] = $currentKey;
                $currentKey = getAlternativeApiKey($usedKeys);
                usleep(RETRY_DELAY_MS * 1000);
                continue;
            }

            return ['error' => '잘못된 응답 형식입니다. (재시도 ' . ($attempt + 1) . '회 실패)'];
        }

        // 성공!
        if ($attempt > 0) {
            error_log("Gemini API 성공: {$attempt}회 재시도 후 성공");
        }

        return $data;
    }

    // 모든 재시도 실패
    return ['error' => "모든 재시도 실패 ({$lastHttpCode}): {$lastError}"];
}

/**
 * 세션 저장
 */
function saveSession($sessionId, $data) {
    $filename = SESSION_DIR . preg_replace('/[^a-zA-Z0-9_]/', '', $sessionId) . '.json';
    @file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * 세션 로드
 */
function loadSession($sessionId) {
    $filename = SESSION_DIR . preg_replace('/[^a-zA-Z0-9_]/', '', $sessionId) . '.json';
    if (file_exists($filename)) {
        $content = @file_get_contents($filename);
        if ($content) {
            return json_decode($content, true);
        }
    }
    return ['history' => []];
}

/**
 * 메인 처리
 */
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'generate':
        // 이미지 생성
        $rawInput = file_get_contents('php://input');

        // 입력 크기 체크 (10MB 제한)
        $inputSize = strlen($rawInput);
        if ($inputSize > 10 * 1024 * 1024) {
            echo json_encode(['error' => '요청 크기가 너무 큽니다. 이미지 크기를 줄여주세요. (현재: ' . round($inputSize / 1024 / 1024, 2) . 'MB)']);
            exit;
        }

        $input = json_decode($rawInput, true);

        if (!$input) {
            // JSON 파싱 실패 시 더 자세한 에러
            $jsonError = json_last_error_msg();
            echo json_encode(['error' => 'JSON 파싱 오류: ' . $jsonError, 'inputSize' => $inputSize]);
            exit;
        }

        // 프롬프트나 이미지 중 하나는 있어야 함
        $hasPrompt = isset($input['prompt']) && !empty(trim($input['prompt']));
        $hasImages = isset($input['images']) && !empty($input['images']);

        if (!$hasPrompt && !$hasImages) {
            http_response_code(400);
            echo json_encode(['error' => '프롬프트 또는 이미지가 필요합니다.']);
            exit;
        }

        $prompt = isset($input['prompt']) ? trim($input['prompt']) : '';
        $model = isset($input['model']) ? $input['model'] : DEFAULT_MODEL;
        $sessionId = isset($input['sessionId']) ? $input['sessionId'] : uniqid('session_');
        $images = isset($input['images']) ? $input['images'] : [];

        // 이미지 모델인지 확인
        $isImageModel = (strpos($model, 'image') !== false);

        // 세션 로드 (텍스트 모델일 때만 히스토리 사용)
        $session = loadSession($sessionId);
        $history = isset($session['history']) ? $session['history'] : [];

        // contents 구성
        $parts = [];

        // 텍스트 프롬프트 추가
        if ($prompt) {
            $parts[] = ['text' => $prompt];
        }

        // 이미지가 있으면 추가
        foreach ($images as $img) {
            if (isset($img['data']) && isset($img['mimeType'])) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $img['mimeType'],
                        'data' => $img['data']
                    ]
                ];
            }
        }

        // 현재 사용자 메시지
        $currentUserMessage = [
            'role' => 'user',
            'parts' => $parts
        ];

        // 멀티턴 대화 구성
        if ($isImageModel) {
            // 이미지 모델은 멀티턴 지원 제한적 - 단일 요청만 사용
            $contents = [$currentUserMessage];
        } else {
            // 텍스트 모델은 히스토리 포함
            $contents = array_merge($history, [$currentUserMessage]);
        }

        // generationConfig 구성
        $generationConfig = [];

        if ($isImageModel) {
            // 이미지 생성 모델용 설정 (Google Gen AI SDK 형식)
            $generationConfig['responseMimeType'] = 'text/plain';
            $generationConfig['responseModalities'] = ['Text', 'Image'];
        }

        // 디버그용 모델 저장
        $GLOBALS['debugModel'] = $model;

        // 디버그 로그
        error_log("Gemini API 요청 - Model: {$model}");
        error_log("GenerationConfig: " . json_encode($generationConfig));

        // API 호출 (자동 재시도 포함)
        $response = callGeminiAPI($model, $contents, $generationConfig);

        if (isset($response['error'])) {
            // 에러여도 200으로 응답하고 JSON으로 에러 전달
            echo json_encode($response);
            exit;
        }

        // 응답 파싱
        $result = [
            'sessionId' => $sessionId,
            'text' => '',
            'images' => []
        ];

        $modelResponseParts = [];

        if (isset($response['candidates'][0]['content']['parts'])) {
            $responseParts = $response['candidates'][0]['content']['parts'];

            foreach ($responseParts as $part) {
                if (isset($part['text'])) {
                    $result['text'] .= $part['text'];
                    $modelResponseParts[] = ['text' => $part['text']];
                }
                if (isset($part['inlineData'])) {
                    $result['images'][] = [
                        'data' => $part['inlineData']['data'],
                        'mimeType' => $part['inlineData']['mimeType']
                    ];
                    $modelResponseParts[] = [
                        'inlineData' => [
                            'mimeType' => $part['inlineData']['mimeType'],
                            'data' => $part['inlineData']['data']
                        ]
                    ];
                }
            }
        } elseif (isset($response['candidates'][0]['finishReason'])) {
            $result['text'] = '응답을 생성할 수 없습니다. (사유: ' . $response['candidates'][0]['finishReason'] . ')';
            $modelResponseParts[] = ['text' => $result['text']];
        }

        // 텍스트 모델일 때만 히스토리 저장
        if (!$isImageModel && !empty($modelResponseParts)) {
            // 히스토리에 사용자 메시지와 모델 응답 추가
            $history[] = $currentUserMessage;
            $history[] = [
                'role' => 'model',
                'parts' => $modelResponseParts
            ];

            // 세션 저장
            saveSession($sessionId, ['history' => $history]);
        }

        echo json_encode($result);
        break;

    case 'newSession':
        // 새 세션 생성
        $sessionId = uniqid('session_');
        echo json_encode(['sessionId' => $sessionId]);
        break;

    case 'clearSession':
        // 세션 초기화
        $input = json_decode(file_get_contents('php://input'), true);
        $sessionId = isset($input['sessionId']) ? $input['sessionId'] : '';

        if ($sessionId) {
            $filename = SESSION_DIR . preg_replace('/[^a-zA-Z0-9_]/', '', $sessionId) . '.json';
            if (file_exists($filename)) {
                @unlink($filename);
            }
        }

        echo json_encode(['success' => true]);
        break;

    case 'test':
        // API 키 테스트
        $allKeys = getAllApiKeys();
        echo json_encode([
            'status' => 'ok',
            'apiKeySet' => !empty(GEMINI_API_KEY) && GEMINI_API_KEY !== 'YOUR_API_KEY_HERE',
            'apiKeyPreview' => substr(GEMINI_API_KEY, 0, 15) . '...',
            'totalKeys' => count($allKeys),
            'maxRetries' => MAX_RETRIES,
            'defaultModel' => DEFAULT_MODEL,
            'phpVersion' => PHP_VERSION,
            'curlEnabled' => function_exists('curl_init')
        ]);
        break;

    case 'models':
        // 사용 가능한 모델 목록
        echo json_encode([
            'models' => [
                [
                    'id' => 'gemini-3-pro-preview',
                    'name' => 'Gemini 3 Pro',
                    'description' => '고급 추론 및 텍스트 생성'
                ],
                [
                    'id' => 'gemini-3-pro-image-preview',
                    'name' => 'Gemini 3 Pro Image (Nano Banana 2)',
                    'description' => '고품질 이미지 생성, 최대 4K 해상도'
                ]
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => '유효하지 않은 액션입니다.', 'actions' => ['generate', 'newSession', 'clearSession', 'models', 'test']]);
}
?>
