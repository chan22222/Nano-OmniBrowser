<?php
// Gemini API 설정
// 여러 API 키를 순환하여 사용 (Rate Limit 분산)

$API_KEYS = [
    // 프로젝트 1
    'AIzaSyCB5nqQTqE9T6FIOUwUCei0QKoEc3CZqbw',
    'AIzaSyCFOnruiJPj2e_om56qGjZfAa_01m08T_o',
    'AIzaSyC-kc8TZGq4uyyUBRMe5y8EbtuTK3w4dtc',
    'AIzaSyANVwIYuxKJprcsgffEduIa2I3FVxesBow',
    'AIzaSyCrVlEG106Zi_WnaKTZKzup63h5FiVdpko',
    'AIzaSyChS_OykOyHWB7mPps84Va9ejV5sLJaG14',
    'AIzaSyDb5lkq1__5HRYBhCBTZ1s1AVrp6j9W4v8',
    'AIzaSyCJPpwU1JJueNVagBML3-wLgpEEn-GnHSA',
    'AIzaSyDz6UPc60CAObIf_1YvbUP0OgCGpVGddVA',
    'AIzaSyABFLCQ2N6jt7aL-6XxkQmktepGVf_469c',
    // 프로젝트 2 (새 키)
    'AIzaSyDpv4ORv49ik-deualOJdnZSPwRFunGbhE',
    'AIzaSyAZ1-HGrv2cp4KbVd7tzL-sjh8oIrHUsuA',
    'AIzaSyBs5LObH-ed_UEr0eZYj43v270ScQ325y8',
    'AIzaSyDYv8Hysy5GYx_wYVciuDmWiCtLnhcpzVQ',
    'AIzaSyA_4J8X8M3lrbDplCZej_gGbDfM5YGTs6c',
    'AIzaSyAldotETMYrqpGSBT88wOZauG-dv188baU',
    'AIzaSyBMS1fgUu5clETwTM9ScS1IakAIY7fezGE',
    'AIzaSyCV4aM8OTuTVMq1zE1xeFxX3j1hpj6F4zw',
    'AIzaSyAj-OPe6U0RanoIy5HPRd_RGUZZOuSHZT4',
    'AIzaSyAI8ly4mIoHa1dJJIXSYKKfhgNsNqOyHe8'
];

// 실패한 키 추적 (메모리 캐시 - 요청당)
$FAILED_KEYS = [];

// 랜덤하게 API 키 선택
function getRandomApiKey() {
    global $API_KEYS;
    return $API_KEYS[array_rand($API_KEYS)];
}

// 순차적으로 API 키 선택 (파일 기반 카운터)
function getNextApiKey() {
    global $API_KEYS;
    $counterFile = __DIR__ . '/sessions/api_counter.txt';

    // 카운터 읽기
    $counter = 0;
    if (file_exists($counterFile)) {
        $counter = (int)file_get_contents($counterFile);
    }

    // 현재 키 선택
    $key = $API_KEYS[$counter % count($API_KEYS)];

    // 카운터 증가 및 저장
    file_put_contents($counterFile, ($counter + 1) % count($API_KEYS));

    return $key;
}

// 특정 키를 제외하고 다른 키 선택 (재시도용)
function getAlternativeApiKey($excludeKeys = []) {
    global $API_KEYS;

    // 사용 가능한 키 필터링
    $availableKeys = array_filter($API_KEYS, function($key) use ($excludeKeys) {
        return !in_array($key, $excludeKeys);
    });

    if (empty($availableKeys)) {
        // 모든 키가 실패하면 랜덤하게 하나 선택
        return $API_KEYS[array_rand($API_KEYS)];
    }

    // 사용 가능한 키 중 랜덤 선택
    return $availableKeys[array_rand($availableKeys)];
}

// 모든 API 키 가져오기 (재시도 로직용)
function getAllApiKeys() {
    global $API_KEYS;
    return $API_KEYS;
}

// 현재 사용할 API 키 (랜덤 방식 - 동시 접속 시 분산)
define('GEMINI_API_KEY', getRandomApiKey());

// 사용할 모델 선택
// gemini-2.5-flash-image: 빠른 이미지 생성
// gemini-3-pro-image-preview: 고품질 이미지 생성 (기본)
define('DEFAULT_MODEL', 'gemini-3-pro-image-preview');

// CORS 설정 (필요시 수정)
define('ALLOWED_ORIGIN', '*');

// 업로드 파일 최대 크기 (바이트)
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

// 세션 저장 디렉토리 (채팅 기록용)
define('SESSION_DIR', __DIR__ . '/sessions/');

// 재시도 설정
define('MAX_RETRIES', 5);           // 최대 재시도 횟수
define('RETRY_DELAY_MS', 500);      // 재시도 간 대기 시간 (밀리초)
?>
