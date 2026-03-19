/**
 * Y, 2026.3.19
 * Working in Post Template of Query Loop Block
 * Add Block: Widgets / Dynamic Post Badge
 */
// 1. 에디터 UI 블록 등록 (자바스크립트 주입)
add_action( 'enqueue_block_editor_assets', function() {
    wp_register_script( 'custom-dynamic-post-badge-js', '', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ) );
    
    $script = "
        ( function( blocks, element ) {
            var el = element.createElement;
            blocks.registerBlockType( 'custom/dynamic-post-badge', {
                title: 'Dynamic Post Badge',
                icon: 'tag',
                category: 'widgets',
                edit: function() {
                    return el( 'div',
					           { style: { background: '#ff0000', color: '#fff', padding: '10px', textAlign: 'center', borderRadius: '4px' } },
					           'Dynamic Post Badge (Preview)' );
                },
                save: function() { return null; },
            } );
        }( window.wp.blocks, window.wp.element ) );
    ";
    wp_add_inline_script( 'custom-dynamic-post-badge-js', $script );
    wp_enqueue_script( 'custom-dynamic-post-badge-js' );
} );

// 2. 서버 사이드 렌더링
add_action( 'init', function() {
    register_block_type( 'custom/dynamic-post-badge', array(
        'render_callback' => function( $attributes, $content, $block ) {
            static $style_rendered = false;

            // Query Loop 내부의 postId 확인
            $post_id = isset( $block->context['postId'] ) ? $block->context['postId'] : get_the_ID();
            if ( ! $post_id ) return '';

            $tags = get_the_tags( $post_id );
            if ( ! $tags ) return '';

            // 표시할 대상 태그와 변환 문구 설정
            // '태그슬러그' => '출력문구'
            $target_map = [
                'shopify' => 'SELLING', // 실제 태그가 shopify라면 이렇게 작성
                'selling' => 'SELLING', // 실제 태그가 selling일 경우 대비
                'new'     => 'NEW',
                'hot'     => 'HOT',
                'sale'    => 'SALE'
            ];

            $found_texts = [];
            foreach ( $tags as $tag ) {
                $tag_name = strtolower( $tag->name );
                if ( isset( $target_map[$tag_name] ) ) {
                    $found_texts[] = $target_map[$tag_name];
                }
            }

            if ( ! empty( $found_texts ) ) {
                $display_text = implode( ' | ', array_unique( $found_texts ) );
                
                $output = '';
                if ( ! $style_rendered ) {
                    $output .= '
                    <style>
                        .wp-block-post, .kb-post-grid-col { position: relative !important; }
                        .dynamic-combined-badge {
                            position: absolute;
                            top: 15px;
                            left: 15px;
                            background: #ff0000;
                            color: #ffffff;
                            padding: 4px 6px;
                            font-size: 11px;
                            font-weight: bold;
                            border-radius: 3px;
                            z-index: 99;
                            text-transform: uppercase;
                            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                            line-height: 1.2;
                            white-space: nowrap;
                        }
                    </style>';
                    $style_rendered = true;
                }

                $output .= '<div class="dynamic-combined-badge">' . esc_html( $display_text ) . '</div>';
                return $output;
            }

            return '';
        },
    ) );
} );

