/** Y, 2026.2.28, 3.3 - 4, 3.10
 * MongoDB REST API Shortcode
 * <ipTime AX3000M Reserved Address>
 *   192.168.0.8 yRocketStation1
 * <Windows Inbound Rule>
 *   >> netsh advfirewall firewall add rule name="MongoDB_API_3000" dir=in action=allow protocol=TCP localport=3000
 *   >> netsh advfirewall firewall add rule name="MongoDB_API_3001" dir=in action=allow protocol=TCP localport=3001
 * <WordPress Shortcode>
 *   [mongodb_data type="find" collection="schedule_board" port="3000"]
     {
	   "_id": "control"
	 }
     [/mongodb_data]
*    [mongodb_data type="find" collection="watching_usa"]
     { 
        "_id.time": { "$gte": "2023-10-26T00:00:00Z" },
        "_id.code": "DJI"
     }
     [/mongodb_data]
 *   [mongodb_data type="aggregate"  collection="watching_usa" port='3000']
     [
       {
        "$match": {"_id.time": {"$gte": "_A_WEEK_AGO_"}}
       },
       {
         "$sort": {"_id.time": -1}
       },
       {
         "$limit": 5
       }
     ]
     [/mongodb_data]
 */
function _mongodb_data($atts, $content = null) {
    // 1. 파라미터 기본값 설정
    $atts = shortcode_atts(array(
		'collection' => 'heartbeat',      // 컬렉션 이름
        'port' => '3000',    // api port number
        'type' => 'find',    // find 또는 aggregate
		// 'query' => '{}',    // JSON 문자열 쿼리
	), $atts);
	$collection = $atts['collection'];
    $port = $atts['port'];

	// 숏코드 사이의 내용($content)을 쿼리로 사용합니다.
    // 워드프레스 에디터가 자동으로 넣는 <p> 태그 등을 제거합니다.
    $query = !empty($content) ? trim(strip_tags($content)) : '';
	if (empty($query)) {
      return "❌ Query가 없습니다";
     }
	// Replace the reserved word in the query string: _TODAY_, _YESTERDAY_, _A_WEEK_AGO_, _2_WEEKS_AGO_
	$query = convert_static_time_keywords_in_query($query);
	// Replace the reserved word in the query string: _LAST_1_DAY_, _LAST_2_DAYS_, _LAST_14_DAYS_, _LAST_4_WEEKS_
	$lastest_timestamp = get_latest_mongodb_timestamp($collection, $field = '_id.time', $port);
	//echo '<strong>🚀 DEBUGGING: $lastest_timestamp=</strong>' . esc_html($lastest_timestamp);
	$query = convert_dynamic_time_keywords_in_query($query, $end_time=$lastest_timestamp);
	//echo '<strong>🚀 DEBUGGING: $query=</strong>' . esc_html($query);

    // 2. URL 구성
    $pc_ip = MY_DESKTOP_IP;  // 윈도우 PC의 IPv4 주소
	$base_url = "http://{$pc_ip}:{$atts['port']}/api/{$atts['collection']}"; 
	  	
    // 3. 쿼리 스트링 생성
    $url = add_query_arg(array(
        $atts['type'] => $query
    ), $base_url);
	if (false) {
        echo "<div style='position:fixed; top:0; left:0; width:100%; z-index:9999; background:yellow; color:black; padding:15px; border-bottom:3px solid red; font-size:18px; font-family:monospace; word-break:break-all;'>";
        echo "<strong>🚀 DEBUG URL:</strong> <a href='" . esc_url($url) . "' target='_blank'>" . esc_html($url) . "</a>";
        echo "</div>";
	}

    // 4. API 호출
    $response = wp_remote_get($url, array('timeout' => 7));

    if (is_wp_error($response)) {
        return "❌ API 연결 실패: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    // 5. 응답 결과 처리
    if (isset($result['error'])) {
		// echo "<div>" . esc_html(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "</div>";
		$status = esc_html($result['status'] ?? '#');
        $message = esc_html($result['message'] ?? '');
		$details = esc_html($result['details'] ?? '');
        return "❌ 서버 오류: [" . $status . "] " . $message . ' / ' . $details;
    }

    if (empty($result['data'])) {
        return "📭 조회된 데이터가 없습니다.";
    }

    // 6. HTML 출력 생성 (예시: 리스트 형태)
    $output = '<ul class="mongodb-data-list">';
    foreach ($result['data'] as $item) {
        $output .= '<li>' . esc_html(json_encode($item, JSON_UNESCAPED_UNICODE)) . '</li>';
    }
    $output .= '</ul>';

    return $output;
}
add_shortcode('mongodb_data', '_mongodb_data');