/* Y, 2026.3.9, 3.17
 * [fraction_badge n=1 d=6 font_size='18px']
 */
add_shortcode('fraction_badge', '_fraction_badge');
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
	
	// 정적 변수로 스타일 출력 여부 추적
    static $style_printed = false;
	
	// 따옴표 제거 (보안 및 오류 방지)
    $font_size = str_replace(array("'", '"'), '', $a['font_size']);
    $fg_color = str_replace(array("'", '"'), '', $a['color']);
    $bg_color = str_replace(array("'", '"'), '', $a['background_color']);
    $padding = str_replace(array("'", '"'), '', $a['padding']);
	
	// 인라인 스타일로 구성 (각 요소마다 개별 적용됨)
    $style = "font-size: {$font_size}; color: {$fg_color}; background-color: {$bg_color}; padding: {$padding}";
	
	// CSS와 HTML을 합쳐서 반환
	$output = '';

    if (!$style_printed) {
        $output .= '
        <style>
            .fraction-badge {
                border-radius: 8px;
                display: inline-flex;
                align-items: baseline;
                justify-content: center;
                font-family: Arial, sans-serif;
                font-weight: bold;
                line-height: 1.1;
            }
            .f-num { font-size: 1em; }
            .f-slash { font-size: 0.7em; margin: 0 1px; opacity: 0.8; }
            .f-den { font-size: 0.7em; opacity: 0.8;}
        </style>';
        $style_printed = true; // 이후 호출 시에는 style을 출력하지 않음
	}

	$output .= '
    <div class="fraction-badge" style="' . esc_attr($style) . '">
        <span class="f-num">' . esc_html($a['n']) . '</span>
        <span class="f-slash">/</span>
        <span class="f-den">' . esc_html($a['d']) . '</span>
    </div>';

    return $output;
}
