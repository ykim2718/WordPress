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

	const OPTION      = 'kwc_topics';
	const PULL_OPTION = 'kwc_last_pull';
	const NAMESPACE   = 'key-word-cloud/v1';
	const CRON_HOOK   = 'kwc_pull_topics';

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
		$stored = self::store( $request->get_json_params() );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		return new WP_REST_Response( $stored, 200 );
	}

	/**
	 * 받은 자료를 검증해 option 에 넣는다. REST 와 하루 한 번의 가져오기가 같이 쓴다.
	 *
	 * @param mixed $body {"generator": "...", "topics": [...]} 형태의 배열.
	 * @return array|WP_Error stored 와 rejected 를 가진 배열.
	 */
	public static function store( $body ) {
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

		return array(
			'stored'   => count( $topics ),
			'rejected' => $rejected,
		);
	}

	/**
	 * 하루 한 번 topic 을 가져오는 일정을 건다. 활성화와 설정 저장에서 부른다.
	 *
	 * @param bool $enabled 켤 것인가.
	 */
	public static function schedule( $enabled ) {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $enabled ) {
			if ( $next ) {
				wp_unschedule_event( $next, self::CRON_HOOK );
			}
			return;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * 정해진 주소에서 topic 을 받아 저장한다. 하루 한 번 cron 이 부른다.
	 *
	 * 파이프라인은 GPU 가 있는 기계에서 돌고 결과를 그 주소에 둔다. 여기서는 받아
	 * 넣기만 하므로, 갱신 이후 쓴 글은 파이프라인을 다시 돌리기 전까지 반영되지 않는다.
	 *
	 * @return array|WP_Error
	 */
	public static function pull() {
		$options = KWC_Cloud::options();
		$url     = trim( (string) $options['pull_url'] );
		if ( '' === $url ) {
			error_log( '[key-word-cloud] daily pull is on but no URL is set' );
			return new WP_Error( 'kwc_no_pull_url', '가져올 주소가 비었다.' );
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'User-Agent' => 'wp-key-word-cloud/' . KWC_VERSION ),
		) );

		if ( is_wp_error( $response ) ) {
			// 조용히 넘기면 구름이 몇 달째 낡은 이유를 알 수 없게 된다.
			error_log( '[key-word-cloud] pull failed: ' . $response->get_error_message() . ' url=' . $url );
			self::note_pull( 'failed: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( '[key-word-cloud] pull HTTP ' . $code . ' url=' . $url );
			self::note_pull( 'HTTP ' . $code );
			return new WP_Error( 'kwc_pull_http', 'HTTP ' . $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$done = self::store( $body );
		if ( is_wp_error( $done ) ) {
			error_log( '[key-word-cloud] pulled body was unusable: ' . $done->get_error_message() . ' url=' . $url );
			self::note_pull( 'unusable: ' . $done->get_error_message() );
			return $done;
		}

		self::note_pull( 'stored ' . $done['stored'] );
		return $done;
	}

	/**
	 * 마지막 가져오기의 시각과 결과를 남긴다. 성공만 남기면 실패가 보이지 않는다.
	 *
	 * @param string $result 한 줄 요약.
	 */
	private static function note_pull( $result ) {
		update_option( self::PULL_OPTION, array(
			'at'     => gmdate( 'c' ),
			'result' => $result,
		), false );
	}

	/**
	 * 마지막 가져오기 기록.
	 *
	 * @return array at, result.
	 */
	public static function last_pull() {
		$saved = get_option( self::PULL_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return array( 'at' => '', 'result' => '' );
		}
		return array(
			'at'     => isset( $saved['at'] ) ? (string) $saved['at'] : '',
			'result' => isset( $saved['result'] ) ? (string) $saved['result'] : '',
		);
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
