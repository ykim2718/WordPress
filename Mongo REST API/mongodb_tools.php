/** Y, 2026.3.5
 * 배열 데이터에서 'dot notation' 키(예: _id.time)를 사용하여 값을 추출하는 함수
 */
function get_nested_value_by_key($data, $field = '_id.time') {
    if (empty($data) || !is_array($data)) return '';

    // 점(.)이 포함된 경우 (중첩 구조)
    if (strpos($field, '.') !== false) {
        $keys = explode('.', $field);
        $value = $data;

        foreach ($keys as $key) {
            // 배열 내에 키가 존재하면 안으로 진입
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return ''; // 중간에 키가 없으면 빈 문자열 반환
            }
        }
        return $value;
    }

    // 단일 키인 경우
    return $data[$field] ?? '';
}



/** Y, 2026.3.4 - 5
 * MongoDB에서 최신 타임스탬프 1개를 가져오는 함수
 * @param string $collection 컬렉션 이름
 * @param string $field 체크할 시간 필드명 (기본: _id.time)
 */
function get_latest_mongodb_timestamp($collection = 'watching_usa', $field = '_id.time', $port = '3000') {
    $pc_ip = MY_DESKTOP_IP;
    
    $pipeline = json_encode([
        ['$sort' => [$field => -1]],  // 작은따옴표를 쓰면 PHP는 내부의 $를 변수로 보지 않고 문자 그대로 유지합니다.
        ['$limit' => 1]
    ]);
    $url = add_query_arg(
        array('type' => 'aggregate', 'aggregate' => $pipeline), 
        "http://{$pc_ip}:{$port}/api/{$collection}"
    );

    $response = wp_remote_get($url, array('timeout' => 5));
    if (is_wp_error($response)) return null;

    $result = json_decode(wp_remote_retrieve_body($response), true);

     // 4. 데이터가 존재하면 헬퍼 함수를 사용하여 값 추출
    if (!empty($result['data']) && isset($result['data'][0])) {
        return get_nested_value_by_key($result['data'][0], $field);
    }

    return '';
}



/* Y, 2026.3.4
 */
// Replace the reserved word in the query string: _TODAY_, _YESTERDAY_, _A_WEEK_AGO_, _2_WEEKS_AGO_
function convert_static_time_keywords_in_query($query = '') {
    if (empty($query)) {
        return '';
    }

    $now = new DateTime();
    $iso_format = 'Y-m-d\TH:i:s.000\Z';

    // 1. 오늘 시작 (00:00:00)
    $today_start = (clone $now)->format('Y-m-d') . 'T00:00:00.000Z';
    
    // 2. 상대적 시간들 (현재 시간 기준 24시간, 7일 전 등)
    $yesterday     = (clone $now)->modify('-1 day')->format($iso_format);
    $a_week_ago    = (clone $now)->modify('-1 week')->format($iso_format);
    $two_weeks_ago = (clone $now)->modify('-2 weeks')->format($iso_format);

    // 3. 치환 실행
    $query = str_replace('_TODAY_',        $today_start,    $query);
    $query = str_replace('_YESTERDAY_',    $yesterday,      $query);
    $query = str_replace('_A_WEEK_AGO_',   $a_week_ago,     $query);
    $query = str_replace('_2_WEEKS_AGO_',  $two_weeks_ago,  $query);

    return $query;
}
// Replace the reserved word in the query string: _LAST_1_DAY_, _LAST_2_DAYS_, _LAST_14_DAYS_, _LAST_4_WEEKS_
function convert_dynamic_time_keywords_in_query($query = '', $end_time = '') {
    if (empty($query)) return '';

    // 1. 기준 시간(Base Timestamp) 설정
    // $end_time이 있으면 해당 시간을 타임스탬프로 변환, 없으면 현재 시간(time()) 사용
    $base_ts = !empty($end_time) ? strtotime($end_time) : time();

    // 2. 정규표현식 패턴: _LAST_(숫자)_(단위)_
    // 예: _LAST_1_DAY_, _LAST_14_DAYS_, _LAST_4_WEEKS_, _LAST_6_HOURS_
    $pattern = '/_LAST_(\d+)_([A-Z]+)_/';

    // 3. 콜백 함수를 통한 치환
    $query = preg_replace_callback($pattern, function($matches) use ($base_ts) {
        $amount = $matches[1];           // 숫자 (1, 14, 4 등)
        $unit   = strtolower($matches[2]); // 단위 (DAY, DAYS, WEEKS 등)

        // PHP strtotime 형식을 위해 단수/복수 처리 보정 (S 제거 등)
        // 사실 PHP strtotime은 '14 days'와 '14 day' 둘 다 잘 알아듣습니다.
        
        // 4. 기준 시간($base_ts)으로부터 상대적 시간 계산
        $target_ts = strtotime("-{$amount} {$unit}", $base_ts);
        
        // MongoDB ISO8601 형식으로 반환
        return date('Y-m-d\TH:i:s.000\Z', $target_ts);
    }, $query);

    return $query;
}