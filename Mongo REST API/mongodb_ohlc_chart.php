/* Y, 2026.3.1 - 6, 3.10, 3.12 - 20, 3.26 - 29
 * MongoDB 데이터를 가져와서 실시간 다중 종목 차트를 그리는 숏코드
 * [mongodb_ohlc_chart type="find" collection="watching_usa" interval="60" x_span_hours="24" timeout="7"]
   {
     "_id.time": {"$gte": "2026-03-03T00:00:00.000Z"}
   }
   [/mongodb_ohlc_chart]
 * [mongodb_ohlc_chart type="aggregate"  collection="watching_usa" port='3000']
   [
      {
         "$match": {"_id.time": {"$gte": "_LAST_1_DAY_"}}
      },
      {
         "$sort": {"_id.time": -1}
      },
      {
         "$limit": 100
      }
   ]
   [/mongodb_ohlc_chart]
 */

add_shortcode('mongodb_ohlc_chart', '_mongodb_ohlc_chart');
function _mongodb_ohlc_chart($atts, $content = null) {
    // 1. 파라미터 설정 (timeout, x_span_hours 추가)
    $atts = shortcode_atts(array(
        'collection'    => 'heartbeat',
        'port'          => '3000',      // yControl collection
        'type'          => 'find',      // find or aggregate
        'interval'      => '60',        // 갱신 주기 (초)
        'duration'      => '3600',      // 전체 작동 시간 (초)
        'timeout'       => '7',         // API 타임아웃 (초)
        'x_span_hours'  => '24',        // X축 표시 범위 (시간)
		'time_field'    => '_id.time',
		'canvas_height' => '600px'
    ), $atts);
    $collection = $atts['collection'];
    $port = $atts['port'];
	$interval = (int)((float)$atts['interval']);   // sec
	$time_field = $atts['time_field'];
	$canvas_height = $atts['canvas_height'];
	
	// 숏코드 사이의 내용($content)을 쿼리로 사용합니다.
    if (null === $content || empty(trim($content))) {
        return "❌ Query가 없습니다. 숏코드 닫는 태그(/)와 오타를 확인하세요.";
    }
    $query = strip_tags($content);
	$query = wp_strip_all_tags($query); // 워드프레스 권장 태그 제거
	$query = html_entity_decode($query, ENT_QUOTES, 'UTF-8');
    $query = str_replace(array('<p>', '</p>', '<br />', '<br>'), '', $query);
	$query = str_replace(array("'", '“', '”', '‘', '’'), '"', $query); // 스마트 따옴표 치환
    $query = !empty($query) ? trim(strip_tags($query)) : '';
	if (empty($query)) {
        return "❌ Query 다듬기 후 내용이 사라졌습니다. 원본: " . esc_html($content);
    }
	// JSON 유효성 검사
    if (!json_decode($query)) {
		$error_msg = json_last_error_msg();  // "Syntax error", "State mismatch" 등 출력
		echo_variable_value('$query=', $query);
        return "❌ Query가 유효하지 않은 JSON 형식입니다: " . $error_msg; 
    }
	
	// 1. ID 및 $query 생성
	$pure_query = $query;
	$pure_query_for_id = preg_replace('/\s+/', '', $pure_query);
	$instance_id = md5($collection . $port . $pure_query);
    $chart_canvas_id = 'canvas_' . $instance_id;
    $chart_status_id = 'status_' . $instance_id;
	
	// Replace the reserved word in the query string: _TODAY_, _YESTERDAY_, _A_WEEK_AGO_, _2_WEEKS_AGO_
	$query = convert_static_time_keywords_in_query($query);
	// Replace the reserved word in the query string: _LAST_1_DAY_, _LAST_2_DAYS_, _LAST_14_DAYS_, _LAST_4_WEEKS_
	$lastest_timestamp = get_latest_mongodb_timestamp($collection, $time_field, $port);
	//echo '<strong>🚀 DEBUGGING: $lastest_timestamp=</strong>' . esc_html($lastest_timestamp);
	$query = convert_dynamic_time_keywords_in_query($query, $end_time=$lastest_timestamp);

	// 2. URL 구성
    $pc_ip = MY_DESKTOP_IP;
	$url = add_query_arg(array($atts['type'] => $query), "http://{$pc_ip}:{$atts['port']}/api/{$atts['collection']}");

    // 3. API 호출 (timeout 설정 반영)
    $response = wp_remote_get($url, array('timeout' => (int)$atts['timeout']));
    
    if (is_wp_error($response)) return "❌ API 연결 실패: " . $response->get_error_message();

    $node_data = json_decode(wp_remote_retrieve_body($response), true);   // {"data": [...], "status": "ok"}
	
	// 4. 데이터 존재 여부 확인 (isset은 키 존재 여부, is_array는 형식 확인)
    if (!isset($node_data['data']) || !is_array($node_data['data']) || empty($node_data['data'])) {
        return '<div class="mongo-chart-error" style="padding:20px; border:1px solid #ddd; color:#666; text-align:left;">
               ⚠️ 조회된 차트 데이터가 없습니다. (No data found)
			     <ul>
			       <li>collection='.$collection.'</li>
				   <li>port='.$port.'</li>
				   <li>type='.$atts['type'].'</li>
				   <li>query='.$query.'</li>
				 </ul>
			   </div>';
    }
	
	// 5. Field mapping
    $mapping = [
        '현재가' => 'close',
        '시가'   => 'open',
        '고가'   => 'high',
        '저가'   => 'low',
        '거래량' => 'volume'
    ];
	foreach ($node_data['data'] as &$item) {
        foreach ($mapping as $old_key => $new_key) {
            if (isset($item[$old_key])) {
                $item[$new_key] = $item[$old_key];
                unset($item[$old_key]);
            }
        }
    }
    unset($item);
	
	// 6. 제어 정보 생성
    $control_info = array(
        'refresh_interval' => $interval,  // sec
        'refresh_end_time' => date('c', time() + (int)$atts['duration']),
        'x_span_hours'  => (int)$atts['x_span_hours'],
        'instance_id'     => $instance_id,
        'chart_canvas_id' => $chart_canvas_id,
        'chart_status_id' => $chart_status_id,
		'canvas_height'   => $canvas_height,
		'ajax_url'        => add_query_arg(
                array('ajax_update' => '1', 'instance_id' => $instance_id), 
                set_url_scheme( 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] )
            )
    );
	
	// 7. AJAX 응답 처리
	if (isset($_GET['ajax_update'])) {
        // 버퍼링 시작 (상단에 이미 출력이 있었을 경우를 대비)
        if (ob_get_length()) ob_clean(); 

        if ($_GET['instance_id'] === $instance_id) {
            $node_data['control'] = $control_info;
       
            // 브라우저에게 JSON이라고 명확히 알려줌
            // wp_send_json 대신 직접 header, echo, exit를 써서 확실히 끊어버립니다.
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($node_data);
			exit;
            // 가끔 테마나 다른 플러그인이 wp_send_json이 실행되기 전에 "이미 다른 걸 출력해버린" 경우,
            // wp_send_json이 가끔 꼬일 때가 있다. 이럴 때 ob_clean()으로 버퍼를 비우고 수동으로 내보내는 방식이
            // 가장 확실한 "강제 종료" 방법이다.
        } else {
            wp_send_json(array(
                'status' => 'ID_MISMATCH_DEBUG',
                'browser_sent' => $_GET['instance_id'],
                'server_generated' => $instance_id,
                'query_used_by_server' => $query // 서버가 지금 어떤 쿼리로 md5를 만들었는지 확인
            ));
        }  
    }
	
    // 8. 초기 렌더링 호출
	return render_mongo_chart_html($node_data['data'], $control_info);
}

// 차트 HTML 및 JS 렌더링 (다중 라인 및 시간축 처리)
function render_mongo_chart_html($chart_data, $control) {
    global $wpdb;

    // 데이터 검증 및 마지막 데이터의 timestamp 추출
    $data_count = count($chart_data);
    if ($data_count >= 1) {
        $last_index = $data_count - 1;
        $last_item = $chart_data[$last_index];
        $last_time_value = get_nested_value_by_key($last_item, '_id.time');
		$last_time_value = get_date_from_gmt($last_time_value, 'Y-m-d H:i:s');
	    $timezone_string = wp_timezone_string();
    } else {
        return '<div style="color:red; padding:20px; border:1px solid #eee;">⚠️ 표시할 데이터가 없습니다.</div>';
    }
    
    // JS 스니펫 추출 (ID: 5785), This applies specifically to the Code Snippets feature of the WPCode Lite plugin.
    $target_id = 5785;
    $js_code = '';
    $raw_data = $wpdb->get_var("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'wpcode_snippets'");
    if ($raw_data) {
        $all_snippets = maybe_unserialize($raw_data);
        if (is_array($all_snippets)) {
            foreach ($all_snippets as $location => $snippets) {
                if (!is_array($snippets)) continue;
                foreach ($snippets as $snippet) {
                    if (isset($snippet['id']) && (int)$snippet['id'] === $target_id) {
                        $js_code = $snippet['code'] ?? '';
                        break 2;
                    }
                }
            }
        }
    }

    $js_code = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "$1", $js_code);
    $js_code = trim(str_ireplace(['<script>', '</script>'], '', $js_code));

    $js_vars = json_encode([
		'chart_data'       => $chart_data,  // 첫 화면용 데이터, One time data
        'x_span_hours'     => (int)$control['x_span_hours'],
        'refresh_interval' => (int)$control['refresh_interval'],  // sec
		'refresh_end_time' => $control['refresh_end_time'],
		'instance_id'      => $control['instance_id'],
		'chart_canvas_id'  => $control['chart_canvas_id'],
		'chart_status_id'  => $control['chart_status_id'],
		'ajax_url'         => $control['ajax_url'],
		'readiness_check_count' => 20,     // max count
		'readiness_check_interval' => 500  // msec
    ]);

    $output = '
	<script type="text/javascript">
        // 데이터를 전역 변수로 즉시 할당
		window.mongoChartVars = window.mongoChartVars || {};
        window.mongoChartVars["' . $control['instance_id'] . '"] = ' . $js_vars . ';
        //console.log("PHP 데이터 전달 완료:", !!window.mongoChartVars);
    </script>
    <div class="mongo-chart-outer-wrapper" style="margin:20px auto; max-width:900px; font-family: sans-serif;">
        <div id="' . $control['chart_canvas_id'] . '" style="width:100%; height:' . $control['canvas_height'] . '; background:#fff;"></div>
        <div id="err-' . $control['instance_id'] . '" style="display:none; margin-top:10px; padding:15px; background:#fff5f5; color:#d35400; font-size:12px;"></div>
        <div style="display:flex; justify-content: space-between; font-size:11px; color:#666; margin-top:10px;">
            <span id="' . $control['chart_status_id'] . '" style="text-align: left; flex: 1; color:green;">● Plotly 엔진 가동 중</span>
            <span style="text-align: right;">
                X-span: ' . esc_html($control['x_span_hours']) . 'h | 
                Count: ' . esc_html($data_count) . ' | 
                Last data time: ' . esc_html($last_time_value) . ' |
                Time zone: ' . esc_html($timezone_string) . '
			</span>
        </div>
    </div>';

    // JS 실행 로직을 Plotly 최적화로 변경
    $output .= '
    <script type="text/javascript">
    (function() {
	    const instanceId = "' . $control['instance_id'] . '";
        const chartVars = window.mongoChartVars[instanceId];
        const errorBox = document.getElementById("err-" + instanceId);

        if (!chartVars) {
            console.error(`데이터 로드 실패: ${instanceId}에 해당하는 mongoChartVars를 찾을 수 없습니다.`);
            return;
        }

        // Plotly 라이브러리 체크 및 실행
        function startPlotlyRender() {
            if (typeof Plotly !== "undefined") {
                try {
                    (function() { ' . $js_code . ' })();  // 스니펫 코드 실행
                } catch (e) {
                    errorBox.style.display = "block";
                    errorBox.innerHTML = "<strong>Runtime Error:</strong> " + e.message;
                    console.error("Plotly Runtime Error:", e);
                }
            } else {
                errorBox.style.display = "block";
                errorBox.innerHTML = "<strong>[Library Error]</strong> Plotly.js를 찾을 수 없습니다. enqueue 설정을 확인하세요.";
            }
        }

        // DOM 로드 완료 후 실행
        if (document.readyState === "complete") {
            startPlotlyRender();
        } else {
            window.addEventListener("load", startPlotlyRender);
        }
    })();
    </script>';

    return $output;
}