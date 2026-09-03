/* Y, 2026.2.8, 2.14 - 15, 3.16 - 17, 9.2
 * assisted by Copilot and Claude
 * [visitor_city_map]
 *
 * 2026.9.2
 * - TRUNCATE 를 걷어내고 캐시를 이어서 쓴다. 365일 창을 없앴다.
 * - 자리를 정할 때 도시명을 먼저 쓴다. WP Statistics 가 이미 city/region 을
 *   채워 두므로 IP 를 외부 API 에 물을 일이 거의 없다. ipwho.is 는 도시명이
 *   빈 행에만 쓰는 예비 수단이다.
 * - wp_statistics_visitor.id 를 표지로 삼아 읽은 데까지만 기억하고, 다음
 *   실행이 그 다음부터 이어 센다. 그래서 visitors 는 기간 제한 없는 누계다.
 */

/* phpMyAdmin SQL:
SELECT ip, city, region, continent, last_view
FROM wp_statistics_visitor
ORDER BY id DESC
LIMIT 100;
 */
function create_geo_cache_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'my_visitor_geo_cache';

    $charset = $wpdb->get_charset_collate();

    $sql = "
        CREATE TABLE IF NOT EXISTS $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(60) NOT NULL,
            city_en VARCHAR(100),
            country VARCHAR(100),
            lat FLOAT,
            lon FLOAT,
            last_view DATETIME,
            visitors INT DEFAULT 1,
			query_days INT DEFAULT 1,
            last_update DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ip_unique (ip)
        ) $charset;
    ";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('init', 'create_geo_cache_table');


/* 도시명이 빈 행에만 쓰는 예비 수단.
 *
 * ipwho.is 무료 한도는 월 10,000건이다. 예전에는 페이지를 열 때마다 모든 IP 를
 * 다시 물어 한도를 곧바로 넘겼고, 넘긴 뒤로는 조회가 전부 실패해 지도가 옛
 * 행 몇 개로 줄어 있었다. 그래서 부르는 자리를 아래 둘로 묶어 두었다
 * (거는 쪽은 update_geo_cache 다):
 *
 *   1. WP Statistics 가 city 를 채워 준 행에는 부르지 않는다. 이름이 있으면
 *      좌표는 Nominatim 에서 얻는다. 실제로 대부분이 여기에 해당한다.
 *      즉 city 가 빈 행만 이 함수의 몫이다.
 *   2. 그중에서도 이 함수가 마지막으로 성공한 visitor id 보다 뒤에 쌓인
 *      행에만 묻는다. 그 표지는 yr_geo_ipwho_last_ok_id 옵션에 있고,
 *      성공했을 때만 앞으로 옮긴다.
 *
 * 본 조회는 앞으로만 읽는 고리 안에서 한 번씩만 일어나므로, 위 둘을 합치면
 * 방문 기록 한 줄에 대한 호출은 평생 한 번을 넘지 않는다. 좌표를 못 얻은
 * 행을 다시 훑는 재시도 단계는 이름으로만 물으며 이 함수를 부르지 않는다.
 *
 * @param string $ip 조회할 IP.
 * @return array|false city_en, country, lat, lon. 실패하면 false 이고
 *                     까닭(HTTP 코드와 ipwho.is 의 message)은 error_log 에 남는다.
 */
function geoip_lookup($ip) {
    $url = "https://ipwho.is/$ip";

    $response = wp_remote_get($url, ['timeout' => 5]);
    if (is_wp_error($response)) {
        // 조용히 넘어가면 지도가 왜 줄었는지 아무도 모른다.
        error_log("[yr] geoip_lookup($ip): " . $response->get_error_message());
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!isset($data->success) || $data->success !== true) {
        // ipwho.is 는 한도 초과도 HTTP 200 에 success=false 로 알려준다.
        error_log(sprintf(
            '[yr] geoip_lookup(%s): http=%d, message=%s',
            $ip,
            wp_remote_retrieve_response_code($response),
            isset($data->message) ? $data->message : 'no message'
        ));
        return false;
    }

    return [
        'city_en' => $data->city ?? 'Unknown',
        'country' => $data->country ?? 'Unknown',
        'lat'     => $data->latitude ?? 0,  // ipwho.is는 lat 대신 latitude를 사용합니다.
        'lon'     => $data->longitude ?? 0  // ipwho.is는 lon 대신 longitude를 사용합니다.
    ];
}

function geocode_city($city, $region = null) {

    if (!$city) return false;

    // 검색어 구성
    $query = $city;
    if ($region) {
        $query .= ", $region";
    }

    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($query);

    $response = wp_remote_get($url, [
        'timeout' => 5,
        'headers' => [
            'User-Agent' => 'MyCityMap/1.0'
        ]
    ]);

    if (is_wp_error($response)) {
        error_log("[yr] geocode_city($query): " . $response->get_error_message());
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!$data || !isset($data[0])) {
        error_log(sprintf(
            '[yr] geocode_city(%s): http=%d, 결과 없음',
            $query, wp_remote_retrieve_response_code($response)
        ));
        return false;
    }

    return [
        'lat' => $data[0]->lat,
        'lon' => $data[0]->lon
    ];
}

/* Nominatim 은 초당 1회를 넘기지 말라고 못 박아 두었다. 넘기면 IP 가 막힌다. */
function yr_geocode_city_throttled($city, $region = null) {
    static $last_call = 0.0;

    if ($last_call > 0.0) {
        $wait = 1.1 - (microtime(true) - $last_call);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
    }
    $last_call = microtime(true);

    return geocode_city($city, $region);
}

/* 캐시에 자리 하나를 새로 넣는다.
 *
 * 좌표를 못 얻어도 넣는다. 방문자 수는 세어야 하고, 매 실행마다 같은 자리를
 * 다시 묻느라 예산을 태우면 안 된다. 좌표가 빈 행은 update_geo_cache 의
 * 재시도 단계가 나중에 다시 물어본다.
 */
function yr_cache_insert($key, $city_en, $country, $geo, $last_view) {
    global $wpdb;

    return $wpdb->insert($wpdb->prefix . 'my_visitor_geo_cache', [
        'ip'        => $key,
        'city_en'   => $city_en,
        'country'   => $country,
        'lat'       => $geo ? $geo['lat'] : null,
        'lon'       => $geo ? $geo['lon'] : null,
        'last_view' => $last_view,
        'visitors'  => 1,
    ]);
}

function update_geo_cache() {
    global $wpdb;

    $stats_table = $wpdb->prefix . 'statistics_visitor';
    $cache_table = $wpdb->prefix . 'my_visitor_geo_cache';

    // 한 번 실행에 부를 외부 API 의 상한. 캐시가 비어 있어도 여러 번에
    // 걸쳐 차오르게 한다. 캐시가 차고 나면 이 예산은 한 번도 안 쓰인다.
    $lookup_budget = 10;

    // 어디까지 읽었는지. id 는 늘기만 하므로 날짜보다 안전하다.
    // 날짜로 끊으면 경계에 걸친 같은 날 행을 두 번 세거나 흘린다.
    $last_id = get_option('yr_geo_cache_last_visitor_id', false);

    if (false === $last_id) {
        // 첫 실행. 옛 방식이 남긴 방문자 수를 지우고 0 부터 다시 쌓는다.
        // 위경도는 그대로 두어 다시 물어보지 않는다.
        $wpdb->query("UPDATE $cache_table SET visitors = 0");
        $last_id = 0;
    }
    $last_id = (int) $last_id;

    // ipwho.is 가 마지막으로 성공한 자리. 이보다 앞선 행에는 묻지 않는다.
    // 까닭은 geoip_lookup 의 설명에 적어 두었다.
    $ipwho_ok_id = (int) get_option('yr_geo_ipwho_last_ok_id', 0);

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT id, ip, city, region, last_view
        FROM $stats_table
        WHERE id > %d
        ORDER BY id ASC
    ", $last_id));

    // 빈 배열은 정상이다 - 새 방문이 없을 뿐이다. null 은 질의가 깨진 것이다.
    if (null === $rows) {
        error_log(sprintf(
            '[yr] update_geo_cache: %s 질의 실패. db_error=%s',
            $stats_table, $wpdb->last_error ?: 'unknown'
        ));
        return;
    }

    $done_id  = $last_id;
    $failed   = 0;  // 좌표를 못 얻은 수
    $no_place = 0;  // 도시명도 없고 물어볼 IP 도 없는 수
    $stopped  = false;

    foreach ($rows as $row) {
        $city   = trim((string) $row->city);
        $region = trim((string) $row->region);
        $ip     = (string) $row->ip;

        if ('' !== $city) {
            // WP Statistics 가 이미 도시를 안다. 좌표만 얻으면 된다.
            // 같은 도시는 IP 가 달라도 한 자리로 묶인다.
            $key     = $city . '.' . $region;
            $by_name = true;
        } elseif (strpos($ip, '#hash#') === 0
               || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
            // 해시된 IP 나 내부망 IP 는 도시명이 없으면 자리를 못 정한다.
            $no_place++;
            $done_id = (int) $row->id;
            continue;
        } else {
            // 도시명이 없어 ipwho.is 를 써야 하는 행이다. 무료 한도를 지키려고
            // 마지막으로 성공한 자리보다 뒤에 쌓인 것에만 묻는다.
            if ((int) $row->id <= $ipwho_ok_id) {
                $no_place++;
                $done_id = (int) $row->id;
                continue;
            }
            $key     = $ip;
            $by_name = false;
        }

        $exists = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $cache_table WHERE ip = %s", $key
        ));

        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $cache_table
                    SET visitors  = visitors + 1,
                        last_view = GREATEST(COALESCE(last_view, %s), %s)
                  WHERE id = %d",
                $row->last_view, $row->last_view, $exists->id
            ));
            $done_id = (int) $row->id;
            continue;
        }

        // 새 자리인데 예산이 없다. 여기서 멈추고 다음 실행이 이 행부터 잇는다.
        if ($lookup_budget <= 0) {
            $stopped = true;
            break;
        }
        $lookup_budget--;

        if ($by_name) {
            $geo     = yr_geocode_city_throttled($city, $region);
            $city_en = $city;
            $country = $region;
        } else {
            $geo     = geoip_lookup($key);
            $city_en = $geo ? $geo['city_en'] : null;
            $country = $geo ? $geo['country'] : null;

            if ($geo) {
                // 성공한 자리를 표지로 남긴다. 이보다 앞선 행은 다시 묻지 않는다.
                $ipwho_ok_id = (int) $row->id;
                update_option('yr_geo_ipwho_last_ok_id', $ipwho_ok_id, false);
            }
        }

        if (!$geo) {
            $failed++;
        }

        yr_cache_insert($key, $city_en, $country, $geo, $row->last_view);
        $done_id = (int) $row->id;
    }

    update_option('yr_geo_cache_last_visitor_id', $done_id, false);

    // 예산이 남았으면 지난번에 좌표를 못 얻은 자리를 다시 물어본다.
    //
    // 이름이 있는 자리만 고른다. 여기서 geoip_lookup 을 부르면 같은 행을
    // 페이지 열 때마다 다시 물어 무료 한도를 태우게 된다. ipwho.is 는 본
    // 고리에서 행마다 한 번씩만 쓴다.
    if ($lookup_budget > 0) {
        $blank = $wpdb->get_results($wpdb->prepare(
            "SELECT id, city_en, country
               FROM $cache_table
              WHERE lat IS NULL AND city_en IS NOT NULL AND city_en <> ''
              LIMIT %d",
            $lookup_budget
        ));

        foreach ((array) $blank as $b) {
            $geo = yr_geocode_city_throttled($b->city_en, $b->country);

            if (!$geo) {
                $failed++;
                continue;
            }

            $wpdb->update(
                $cache_table,
                ['lat' => $geo['lat'], 'lon' => $geo['lon']],
                ['id'  => $b->id]
            );
        }
    }

    if ($failed || $no_place || $stopped) {
        error_log(sprintf(
            '[yr] update_geo_cache: 새 행 %d개, id %d 까지 처리 - 좌표 실패 %d, 자리 못 정함 %d, 예산 소진 %s.',
            count($rows), $done_id, $failed, $no_place, $stopped ? 'yes' : 'no'
        ));
    }
}
/*
// 12시간마다 update_geo_cache 실행 예약
function schedule_geo_cache_twice_daily() {
    if (!wp_next_scheduled('twice_daily_geo_cache_event')) {
        wp_schedule_event(time(), 'twicedaily', 'twice_daily_geo_cache_event');
    }
}
add_action('wp', 'schedule_geo_cache_twice_daily');
// 예약된 이벤트가 실행할 함수
add_action('twice_daily_geo_cache_event', 'update_geo_cache');
*/
add_action('wp', function () {
    if (is_page('visitor-city-map-page-slug')) {
        update_geo_cache();
    }
});



function visitor_city_map_shortcode() {
    global $wpdb;
    $table = $wpdb->prefix . 'my_visitor_geo_cache';

    $rows = $wpdb->get_results("
        SELECT city_en, country, lat, lon, visitors, last_view
        FROM $table
        WHERE lat IS NOT NULL AND lon IS NOT NULL AND visitors > 0
		ORDER BY last_view DESC
    ");
	$rows = (array) $rows;

	// 지도에 찍힌 자리의 수
	$location_count = count($rows);

	// 가장 최근 last_view 구하기
	$latest_view_time     = $rows ? $rows[0]->last_view : 'No data';
	$latest_view_location = $rows ? $rows[0]->country . '|' . $rows[0]->city_en : 'No data';

    ob_start();
    ?>
    <div id="visitor-city-map" style="height:600px; margin-bottom:20px;"></div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

    <style>
        .legend {
            background: white;
            padding: 10px;
            line-height: 18px;
            color: #555;
            border-radius: 4px;
        }
        .legend i {
            width: 18px;
            height: 18px;
            float: left;
            margin-right: 8px;
            opacity: 0.8;
        }
    </style>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 세계 지도를 한 장으로 묶는다. 안 그러면 좌우로 끝없이 되풀이된다.
            var map = L.map('visitor-city-map', {
                minZoom: 2,
                maxBounds: [[-90, -180], [90, 180]],
                maxBoundsViscosity: 1.0
            }).setView([20, 0], 2);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                noWrap: true,                     // 타일 되풀이 방지
                bounds: [[-90, -180], [90, 180]]  // 타일 영역 제한
            }).addTo(map);

            var cityData = [
                <?php foreach ($rows as $r): ?>
                {
                    lat: <?= (float) $r->lat ?>,
                    lon: <?= (float) $r->lon ?>,
                    city: "<?= esc_js($r->city_en) ?>",
                    country: "<?= esc_js($r->country) ?>",
                    visitors: <?= (int) $r->visitors ?>
                },
                <?php endforeach; ?>
            ];

            function getColor(visitors) {
                return visitors > 50 ? '#800026' :
                       visitors > 20 ? '#BD0026' :
                       visitors > 10 ? '#E31A1C' :
                       visitors > 5  ? '#FC4E2A' :
                       visitors > 2  ? '#FD8D3C' :
                       visitors > 1  ? '#FEB24C' :
                                       '#FED976';
            }

            cityData.forEach(function(m) {
                var circle = L.circleMarker([m.lat, m.lon], {
                    radius: 8,
                    fillColor: getColor(m.visitors),
                    color: "#333",
                    weight: 1,
                    opacity: 1,
                    fillOpacity: 0.8
                }).addTo(map);

                circle.bindTooltip(
                    m.city + ", " + m.country + "<br>Visitors: " + m.visitors,
                    { permanent: false, direction: "top" }
                );
            });

            var legend = L.control({position: 'bottomright'});

            legend.onAdd = function (map) {
                var div = L.DomUtil.create('div', 'legend'),
                    grades = [0, 1, 2, 5, 10, 20, 50];

                div.innerHTML += "<strong>Visitors</strong><br>";

                for (var i = 0; i < grades.length; i++) {
                    div.innerHTML +=
                        '<i style="background:' + getColor(grades[i] + 1) + '"></i> ' +
                        grades[i] + (grades[i + 1] ? '&ndash;' + grades[i + 1] + '<br>' : '+');
                }

                return div;
            };

            legend.addTo(map);
        });
    </script>

    <div class="last-view-info">
		<span style="margin-right: 13px">Visitor location <?= (int) $location_count ?></span>
		<span style="margin-right: 13px">|</span>
		<span style="margin-right: 13px">Last location <?= esc_html($latest_view_location) ?></span>
		<span style="margin-right: 13px">|</span>
		<span>Last updated <?= esc_html($latest_view_time) ?></span>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('visitor_city_map', 'visitor_city_map_shortcode');
