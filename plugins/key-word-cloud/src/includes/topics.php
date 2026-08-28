<?php
/**
 * 미리 계산된 topic 을 받아 두고 구름에 쓴다.
 *
 * TF-IDF 는 PHP 가 그 자리에서 세지만 topic 은 그럴 수 없다. LLM 추출과 임베딩
 * 클러스터링은 GPU 가 있는 기계에서 도는 Python 파이프라인의 일이고, 여기서는
 * 그 결과를 REST 로 받아 option 에 넣어 두었다가 읽기만 한다.
 *
 * 받는 쪽:
 *   POST /wp-json/key-word-cloud/v1/topics
 *   Authorization: Basic <application password>
 *   {"generator":"...","topics":[{"label":"ai assistant","posts":10,
 *                                 "phrases":["agentic automation", ...]}, ...]}
 *
 * @package KeyWordCloud
 */

defined( 'ABSPATH' ) || exit;

final class KWC_Topics {

	const OPTION    = 'kwc_topics';
	const NAMESPACE = 'key-word-cloud/v1';

	/**
	 * REST route 등록. init 이 아니라 rest_api_init 에서 부른다.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/topics',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'receive' ),
					'permission_callback' => array( __CLASS__, 'may_write' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'send' ),
					'permission_callback' => array( __CLASS__, 'may_write' ),
				),
			)
		);
	}

	/**
	 * 글을 고칠 수 있는 사람만 topic 을 바꾼다.
	 *
	 * @return bool
	 */
	public static function may_write() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * 저장된 topic 을 읽는다.
	 *
	 * @return array label, posts, phrases 를 가진 배열. 없으면 빈 배열.
	 */
	public static function stored() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || empty( $saved['topics'] ) || ! is_array( $saved['topics'] ) ) {
			return array();
		}
		return $saved['topics'];
	}

	/**
	 * 마지막으로 받은 때와 출처.
	 *
	 * @return array updated, generator, count.
	 */
	public static function status() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return array( 'updated' => '', 'generator' => '', 'count' => 0 );
		}
		return array(
			'updated'   => isset( $saved['updated'] ) ? (string) $saved['updated'] : '',
			'generator' => isset( $saved['generator'] ) ? (string) $saved['generator'] : '',
			'count'     => isset( $saved['topics'] ) && is_array( $saved['topics'] ) ? count( $saved['topics'] ) : 0,
		);
	}

	/**
	 * 파이프라인이 보낸 topic 을 검증해 저장한다.
	 *
	 * @param WP_REST_Request $request 요청.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['topics'] ) || ! is_array( $body['topics'] ) ) {
			return new WP_Error( 'kwc_no_topics', 'body 에 topics 배열이 없다.', array( 'status' => 400 ) );
		}

		$topics   = array();
		$rejected = array();
		foreach ( $body['topics'] as $index => $topic ) {
			if ( ! is_array( $topic ) ) {
				$rejected[] = "#{$index}: object 가 아니다";
				continue;
			}
			$label = isset( $topic['label'] ) ? trim( (string) $topic['label'] ) : '';
			$posts = isset( $topic['posts'] ) ? (int) $topic['posts'] : 0;
			if ( '' === $label ) {
				$rejected[] = "#{$index}: label 이 비었다";
				continue;
			}
			if ( $posts < 1 ) {
				$rejected[] = "#{$index} ({$label}): posts 가 1 미만이다";
				continue;
			}

			$phrases = array();
			foreach ( (array) ( isset( $topic['phrases'] ) ? $topic['phrases'] : array() ) as $phrase ) {
				$phrase = trim( (string) $phrase );
				if ( '' !== $phrase ) {
					$phrases[] = sanitize_text_field( $phrase );
				}
			}

			$topics[] = array(
				'label'   => sanitize_text_field( $label ),
				'posts'   => $posts,
				'phrases' => $phrases,
			);
		}

		if ( empty( $topics ) ) {
			// 빈 결과를 성공으로 돌려주면 구름이 조용히 사라진다.
			error_log( '[key-word-cloud] topic upload had no usable topic: ' . implode( '; ', $rejected ) );
			return new WP_Error(
				'kwc_all_rejected',
				'쓸 수 있는 topic 이 하나도 없다: ' . implode( '; ', array_slice( $rejected, 0, 5 ) ),
				array( 'status' => 400 )
			);
		}

		usort( $topics, function ( $a, $b ) {
			return $b['posts'] <=> $a['posts'];
		} );

		update_option( self::OPTION, array(
			'updated'   => gmdate( 'c' ),
			'generator' => isset( $body['generator'] ) ? sanitize_text_field( (string) $body['generator'] ) : '',
			'topics'    => $topics,
		) );
		KWC_Cloud::flush_cache();

		if ( ! empty( $rejected ) ) {
			error_log( '[key-word-cloud] ' . count( $rejected ) . ' topics rejected: '
				. implode( '; ', array_slice( $rejected, 0, 5 ) ) );
		}

		return new WP_REST_Response( array(
			'stored'   => count( $topics ),
			'rejected' => $rejected,
		), 200 );
	}

	/**
	 * 저장된 topic 을 돌려준다. 파이프라인이 무엇이 들어갔는지 확인할 때 쓴다.
	 *
	 * @return WP_REST_Response
	 */
	public static function send() {
		return new WP_REST_Response( get_option( self::OPTION, array() ), 200 );
	}
}
