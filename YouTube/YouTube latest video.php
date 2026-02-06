<?php
/* Y, 2025.12.16 - 17
 * WordPress에서 유튜브 채널의 특정 제목 최신 비디오 표시
   - title 파라미터를 정규표현식 패턴으로 인식하여 영상 제목과 대조: title="/개장전/i", title="/^\[특보\]/", title="/(개장전|마감전)/"
   - max를 통해 "최근 영상 몇 개까지 뒤져볼 것인가"를 결정
   - max 값이 50을 초과할 경우 nextPageToken을 사용하여 재귀적으로 다음 페이지를 호출, 지정된 숫자만큼 전체 영상을 훑으며 정규식 매칭을 수행
   - 매칭되는 영상을 찾는 순간 즉시 API 호출을 중단하고 결과를 반환
   - 캐시 (cache="900" << 15min)를 반드시 사용하여 서버와 API의 부하를 최소화
 **
 * Shortcode: [yt_latest_video title="..." channel_id="UC..." exact="0" max="10" cache="900"]
 * - video_id: 고정 라이브 스트리밍 주소
 * - title: 제목 키워드 (필수), "/개장전/i", "/^\[특보\]/", "/(개장전|마감전)/", 모든 제목 허용 "/./" 
 * - handle: @hkglobalmarket 한경글로벌마켓, @hkwowtv  한국경제TV
 * - cache: 캐시(초) - Transient 저장 시간 (기본 900초 = 15분)
 * - max_search: 영상을 찾을 때까지, 지정한 숫자(예: 200개)만큼 다음 페이지(nextPageToken)를 넘겨가며 영상을 검색
 */
if ( ! function_exists( 'yt_render_iframe' ) ) {
    function yt_render_iframe($id, $title) {
        // 16:9 비율 유지를 위한 컨테이너와 절대 위치 iframe 구조
        $html = '<div class="yt-video-container" style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; max-width:100%;">';
        $html .= '<iframe style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" ';
        $html .= 'src="https://www.youtube.com/embed/' . esc_attr($id) . '" ';
        $html .= 'title="' . esc_attr($title) . '" ';
        $html .= 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" ';
        $html .= 'allowfullscreen></iframe>';
        $html .= '</div>';
        
        return $html;
    }
}
add_shortcode('yt_latest_video', function ($atts) {
    // 1. 파라미터 정의 및 기본값 설정
    $args = shortcode_atts([
		'video_id'          => '',       // NJUjU9ALj4A [한국경제TV LIVE]
        'title'             => '',       // "/개장전/i", "/^\[특보\]/", "/(개장전|마감전)/", 모든 제목 허용 "/./" 
        'handle'            => '',       // 기본값을 비워두어 필수 입력 체크를 수행
        'api_key'           => '',       // 기본값을 비워두어 필수 입력 체크를 수행
        'cache'             => '900',    // 15 min
        'max_searches'      => '500',    // 최대 검색 범위, YouTube 기본은 max 50
        'show_last_if_none' => '1',      // 0: 매칭 안되면 'No Videos', 1: 매칭 안되면 최신영상
    ], $atts, 'yt_latest_video');

    // 2. 입력값 상호 검증
    if (!empty($args['video_id'])) {
        if (!empty($args['title'])) {
            return '<strong>Error:</strong> video_id를 사용할 때는 title을 입력할 수 없습니다.';
        }
        return yt_render_iframe($args['video_id'], 'Fixed Video');
    } 

    if (empty($args['title']) || empty($args['handle'])) {
        return '<strong>Error:</strong> 고정 ID가 없다면 검색을 위한 title과 handle은 필수입니다.';
    }

    // 3. API 키 및 캐시 설정
    $api_key = $args['api_key'] ?: (defined('YOUTUBE_API_KEY') ? YOUTUBE_API_KEY : '');
    if (!$api_key) return 'API Key Missing.';

    // 정규식 패턴 안전 처리 (슬래시가 없는 일반 텍스트 입력 시에도 작동하게 보완)
    $pattern = trim($args['title']);
    if (strpos($pattern, '/') !== 0) {
        $pattern = '/' . preg_quote($pattern, '/') . '/i';
    }

    $transient_key = 'yt_v16_latest_' . md5($args['handle'] . $pattern . $args['max_searches'] . $args['show_last_if_none']);
    if ($args['cache'] !== '0' && ($cached = get_transient($transient_key)) !== false) return $cached;

    // 4. 채널 및 업로드 플레이리스트 조회
    $handle_raw = ltrim(trim($args['handle']), '@');
    $ch_url = "https://www.googleapis.com/youtube/v3/channels?part=contentDetails&forHandle=" . urlencode($handle_raw) . "&key=" . $api_key;
    $ch_res = wp_remote_get($ch_url);
    
    if (is_wp_error($ch_res)) return 'Network Error.';
    $ch_data = json_decode(wp_remote_retrieve_body($ch_res), true);

    if (empty($ch_data['items'])) return 'Channel Not Found: ' . esc_html($args['handle']);
    $playlist_id = $ch_data['items'][0]['contentDetails']['relatedPlaylists']['uploads'];

    // 5. 페이지네이션 검색 루프
    $chosen_video = null;
    $fallback_video = null;
    $next_page_token = '';
    $scanned_count = 0;
    $max_to_scan = intval($args['max_searches']);

    while ($scanned_count < $max_to_scan) {
        $batch_size = min(50, $max_to_scan - $scanned_count);
        $list_url = "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&playlistId=$playlist_id&maxResults=$batch_size&key=$api_key";
        if ($next_page_token) $list_url .= "&pageToken=" . $next_page_token;

        $list_res = wp_remote_get($list_url);
        if (is_wp_error($list_res)) break;
        
        $data = json_decode(wp_remote_retrieve_body($list_res), true);
        if (empty($data['items'])) break;

        if (!$fallback_video) $fallback_video = $data['items'][0];

        foreach ($data['items'] as $item) {
            if (@preg_match($pattern, $item['snippet']['title'])) {
                $chosen_video = $item;
                break 2; // 매칭 성공 시 모든 루프 탈출
            }
        }

        $scanned_count += count($data['items']);
        $next_page_token = isset($data['nextPageToken']) ? $data['nextPageToken'] : '';
        if (!$next_page_token) break;
    }

    // 6. 결과 출력 결정
    $final_id = '';
    $final_title = '';

    if ($chosen_video) {
        $final_id = $chosen_video['snippet']['resourceId']['videoId'];
        $final_title = $chosen_video['snippet']['title'];
    } elseif ($args['show_last_if_none'] === '1' && $fallback_video) {
        $final_id = $fallback_video['snippet']['resourceId']['videoId'];
        $final_title = $fallback_video['snippet']['title'];
    }

    if (!$final_id) return 'No Matching Video Found for: ' . esc_html($args['title']);

    // 7. 렌더링 및 캐시 저장
    $output = yt_render_iframe($final_id, $final_title);

    if ($args['cache'] !== '0') {
        set_transient($transient_key, $output, intval($args['cache']));
    }

    return $output;
});