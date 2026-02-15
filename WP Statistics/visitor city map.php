/* Y, 2026.2.8, 2.14 - 15
 * assisted by Copilot
 * [visitor_city_map]
 */
/* phpMyAdmin SQL:
SELECT ip, city, region, continent
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


function geoip_lookup($ip) {
    $url = "https://ipwho.is/$ip";

    $response = wp_remote_get($url);
    if (is_wp_error($response)) return false;

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!isset($data->success) || $data->success !== true) return false;

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

    if (is_wp_error($response)) return false;

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!$data || !isset($data[0])) return false;

    return [
        'lat' => $data[0]->lat,
        'lon' => $data[0]->lon
    ];
}

function update_geo_cache() {
    global $wpdb;

    $stats_table = $wpdb->prefix . 'statistics_visitor';
    $cache_table = $wpdb->prefix . 'my_visitor_geo_cache';
	$query_days = 365; // 90 DAY, 1 YEAR

	// 캐시 초기화 (항상 fresh하게)
	$wpdb->query("TRUNCATE TABLE $cache_table");

    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT ip, city, region, first_view, last_view
            FROM $stats_table
            WHERE last_view >= DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY id DESC
        ", $query_days)
    );

    foreach ($rows as $row) {
        $ip = $row->ip;
		$first_view = $row->first_view;
		$last_view = $row->last_view;

        // -----------------------------
        // CASE 1: Legacy hashed IP (#hash#...)
        // -----------------------------
        if (strpos($ip, '#hash#') === 0) {
			
			if (empty($row->city)) continue;

            $city = $row->city ?: null;
            $region = $row->region ?: null;
			$ip = $city . '.' . $region;
					
			// legacy 중복 체크: city + region 조합으로 찾기 
			$exists = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM $cache_table WHERE ip = %s AND city_en = %s AND country = %s",
				$ip, $city, $region ));
			if ($exists) {
				// 이미 존재하면 visitors 증가
				$wpdb->query($wpdb->prepare(
					"UPDATE $cache_table SET visitors = visitors + 1 WHERE id = %d",
					$exists->id
				)); 
				continue;
			}

            // 좌표 자동 변환
            $geo = geocode_city($city, $region);

            $lat = $geo ? $geo['lat'] : null;
            $lon = $geo ? $geo['lon'] : null;
			//echo "<pre>city=$city, $lat, $lon</pre>";

            // ip는 NULL로 저장
            $wpdb->insert($cache_table, [
                'ip'       => $ip,
                'city_en'  => $city,
                'country'  => $region,
                'lat'      => $lat,
                'lon'      => $lon,
				'last_view' => $last_view,
                'visitors' => 1,
				'query_days' => $query_days
            ]);

            continue;
        }

        // -----------------------------
        // CASE 2: 내부망 IP
        // -----------------------------
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
            continue;
        }

        // -----------------------------
        // CASE 3: 정상 외부 IP → GeoIP API 호출
        // -----------------------------
        
		// 이미 캐시에 있으면 visitors 증가
        $exists = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $cache_table WHERE ip = %s", $ip
        ));
        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $cache_table SET visitors = visitors + 1 WHERE ip = %s",
                $ip
            ));
            continue;
        }
		
		$geo = geoip_lookup($ip);
        if (!$geo) continue;

        $wpdb->insert($cache_table, [
            'ip'       => $ip,
            'city_en'  => $geo['city_en'],
            'country'  => $geo['country'],
            'lat'      => $geo['lat'],
            'lon'      => $geo['lon'],
			'last_view' => $last_view,
            'visitors' => 1,
			'query_days' => $query_days
        ]);
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
        SELECT city_en, country, lat, lon, visitors, last_view, query_days
        FROM $table
        WHERE lat IS NOT NULL AND lon IS NOT NULL
		ORDER BY last_view DESC
    ");
	
	// 가장 최근 last_view 구하기
	$latest_view_time = $rows ? $rows[0]->last_view : 'No data';
	$latest_view_city = $rows ? $rows[0]->city_en : 'No data';
	$latest_view_country = $rows ? $rows[0]->country : '';
	$latest_view_location = $latest_view_country . '|' . $latest_view_city;
	// Metadata
	$query_days = $rows ? $rows[0]->query_days : 'NA';

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
            var map = L.map('visitor-city-map').setView([20, 0], 2);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18
            }).addTo(map);

            var cityData = [
                <?php foreach ($rows as $r): ?>
                {
                    lat: <?= $r->lat ?>,
                    lon: <?= $r->lon ?>,
                    city: "<?= esc_js($r->city_en) ?>",
                    country: "<?= esc_js($r->country) ?>",
                    visitors: <?= $r->visitors ?>
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
		<span style="margin-right: 13px"><strong>Last location:</strong> <?= esc_html($latest_view_location) ?></span>
		<span style="margin-right: 13px"><strong>Last updated:</strong> <?= esc_html($latest_view_time) ?></span>
		<span><strong>Query days:</strong> <?= esc_html($query_days) ?></span>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('visitor_city_map', 'visitor_city_map_shortcode');

