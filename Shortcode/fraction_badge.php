/* Y, 2026.3.9
 * [fraction_badge n=1 d=6 font_size='18px']
 */
function _fraction_badge($atts) {
    // 숏코드 기본값 설정 (n: 분자, d: 분모)
    $a = shortcode_atts(array(
        'n' => '1',  // numerator
        'd' => '6',  // denomirator
		'font_size' => '20px',
		'padding' => '3px 6px',
		'color' => 'white',
		'background_color' => '#005A9C',
    ), $atts);

    // CSS와 HTML을 합쳐서 반환
    // 팁: 스타일은 한 번만 로드되게 하는 것이 좋지만, 숏코드 내에 포함하면 관리가 가장 쉽습니다.
    $output = '
    <style>
        .fraction-badge {
            background-color: ' . esc_html($a['background_color']) . ';
            border-radius: 8px;
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
			padding: ' . esc_html($a['padding']) . ';
            color: ' . esc_html($a['color']) . ';
			font-size: ' . esc_html($a['font_size']) . ';
            font-family: Arial, sans-serif;
            font-weight: bold;
            ' . esc_html($a['font_size']) . ';
			line-height: 1.1;
        }
        .f-num { font-size: 1em; }
        .f-slash { font-size: 0.7em; margin: 0 1px; opacity: 0.8; }
        .f-den { font-size: 0.7em; }
    </style>
    <div class="fraction-badge">
        <span class="f-num">' . esc_html($a['n']) . '</span>
        <span class="f-slash">/</span>
        <span class="f-den">' . esc_html($a['d']) . '</span>
    </div>';

    return $output;
}
add_shortcode('fraction_badge', '_fraction_badge');