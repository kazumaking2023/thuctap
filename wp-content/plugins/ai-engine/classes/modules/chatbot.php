<?php

define( 'MWAI_CHATBOT_FRONT_PARAMS', [ 'aiName', 'userName', 'guestName', 'textSend', 'textClear', 
	'textInputPlaceholder', 'textInputMaxLength', 'textCompliance', 'startSentence',
	'themeId', 'window', 'icon', 'iconText', 'iconAlt', 'iconPosition', 'fullscreen', 'copyButton'
] );

class Meow_MWAI_Modules_Chatbot {
	private $core = null;
	private $namespace = 'mwai-bot/v1';
	private $isEnqueued = false;
	private $siteWideChatId = null;

	public function __construct() {
		global $mwai_core;
		$this->core = $mwai_core;
		add_shortcode( 'mwai_chatbot_v2', array( $this, 'chat' ) );
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		$this->siteWideChatId = $this->core->get_option( 'chatId' );
		if ( !empty( $this->siteWideChatId ) && $this->siteWideChatId !== 'none' ) {
			$this->registerChatbotScripts();
			add_action( 'wp_footer', array( $this, 'inject_chat' ) );
		}
	}

	public function registerChatbotScripts() {
		if ( $this->isEnqueued ) { 
			return;
		}
		$this->isEnqueued = true;
		// $physical_file = MWAI_PATH . '/app/chatbot-vendor.js';
		// $cache_buster = file_exists( $physical_file ) ? filemtime( $physical_file ) : MWAI_VERSION;
		// wp_register_script( 'mwai_chatbot_vendor', MWAI_URL . '/app/chatbot-vendor.js', [ 'wp-element' ], $cache_buster, false );
		$physical_file = MWAI_PATH . '/app/chatbot.js';	
		$cache_buster = file_exists( $physical_file ) ? filemtime( $physical_file ) : MWAI_VERSION;
		wp_enqueue_script( 'mwai_chatbot', MWAI_URL . '/app/chatbot.js', [ 'wp-element' ], $cache_buster, false );
	}

	public function rest_api_init() {
		register_rest_route( $this->namespace, '/chat', array(
			'methods' => 'POST',
			'callback' => array( $this, 'rest_chat' ),
			'permission_callback' => '__return_true'
		) );
		register_rest_route( $this->namespace, '/images', array(
			'methods' => 'POST',
			'callback' => array( $this, 'rest_images' ),
			'permission_callback' => '__return_true'
		) );
	}

	public function basics_security_check( $params ) {
		if ( empty( $params['newMessage'] ) ) {
			return false;
		}
		if ( empty( $params['chatId'] ) ) {
			return false;
		}
		$length = strlen( trim( $params['newMessage'] ) );
		if ( $length < 1 || $length > ( 4096 - 512 ) ) {
			return false;
		}
		return true;
	}

	public function rest_chat( $request ) {
		try {
			$params = $request->get_json_params();
			if ( !$this->basics_security_check( $params )) {
				return new WP_REST_Response( [ 
					'success' => false, 
					'message' => 'Sorry, your query has been rejected.' ], 403
				);
			}
			$chatbot = $this->core->getChatbot( $params['chatId'] );
			if ( !$chatbot ) {
				return new WP_REST_Response( [ 
					'success' => false, 
					'message' => 'Sorry, your query has been rejected.' ], 403
				);
			}
			
			// Create the QueryText
			$query = new Meow_MWAI_QueryText( $params['newMessage'], 1024 );
			$query->setIsChat( true );

			// Take care of the parameters
			$newParams = [];
			foreach ( $chatbot as $key => $value ) {
				$newParams[$key] = $value;
			}
			foreach ( $params as $key => $value ) {
				$newParams[$key] = $value;
			}
			$params = apply_filters( 'mwai_chatbot_params', $newParams );
			$query->injectParams( $params );

			$takeoverAnswer = apply_filters( 'mwai_chatbot_takeover', null, $query, $params );
			if ( !empty( $takeoverAnswer ) ) {
				return new WP_REST_Response( [ 'success' => true, 'answer' => $takeoverAnswer,
					'html' => $takeoverAnswer, 'usage' => null ], 200 );
			}

			// Moderation
			if ( $this->core->get_option( 'shortcode_chat_moderation' ) ) {
				global $mwai;
				$isFlagged = $mwai->moderationCheck( $query->prompt );
				if ( $isFlagged ) {
					return new WP_REST_Response( [ 
						'success' => false, 
						'message' => 'Sorry, your message has been rejected by moderation.' ], 403
					);
				}
			}

			// Awareness & Embeddings
			$context = null;
			$embeddingsIndex = $params['embeddingsIndex'];
			if ( $query->mode === 'chat' && !empty( $embeddingsIndex ) ) {
				$context = apply_filters( 'mwai_context_search', $query, $embeddingsIndex );
				if ( !empty( $context ) ) {
					$query->injectContext( $context['content'] );
				}
			}

			$answer = $this->core->ai->run( $query );
			$rawText = $answer->result;
			$extra = [];
			if ( $context ) {
				$extra = [ 'embeddings' => $context['embeddings'] ];
			}
			$html = apply_filters( 'mwai_chatbot_reply', $rawText, $query, $params, $extra );
			if ( $this->core->get_option( 'shortcode_chat_formatting' ) ) {
				$html = $this->core->markdown_to_html( $html );
			}
			return new WP_REST_Response( [ 'success' => true, 'answer' => $rawText,
				'html' => $html, 'usage' => $answer->usage ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

	public function rest_images( $request ) {
		try {
			$params = $request->get_json_params();
			$query = new Meow_MWAI_QueryImage( $params['prompt'] );
			$query->injectParams( $params );
			$answer = $this->core->ai->run( $query );
			return new WP_REST_Response( [ 'success' => true, 'images' => $answer->results, 'usage' => $answer->usage ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

	public function inject_chat() {
		$params = $this->core->getChatbot( $this->siteWideChatId );
		if ( !empty( $params ) ) {
			$params['window'] = true;
			$params['id'] = $this->siteWideChatId;
			echo $this->chat( $params );
		}
		return null;
	}

	public function imageschat( $atts ) {
		$atts['mode'] = 'images';
		return $this->chat( $atts );
	}

	public function chat( $atts ) {
		$chatId = isset( $atts['id'] ) ? $atts['id'] : 'default';
		$chatbot = $this->core->getChatbot( $chatId );
		if ( !$chatbot ) {
			return "Chatbot not found.";
		}
		
		$frontParams = [];
		foreach ( MWAI_CHATBOT_FRONT_PARAMS as $param ) {
			if ( isset( $atts[$param] ) ) {
				$frontParams[$param] = $atts[$param];
			}
			else if ( isset( $chatbot[$param] ) ) {
				$frontParams[$param] = $chatbot[$param];
			}
		}

		$frontSystem = [
			'chatId' => $chatId,
			'userData' => $this->core->getUserData(),
			'sessionId' => $this->core->get_session_id(),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'contextId' => get_the_ID(),
			'pluginUrl' => MWAI_URL,
			'restUrl' => untrailingslashit( rest_url() ),
			'debugMode' => $this->core->get_option( 'debug_mode' ),
			'typewriter' => $this->core->get_option( 'shortcode_chat_typewriter' )
		];

		$theme = isset( $frontParams['themeId'] ) ? $this->core->getTheme( $frontParams['themeId'] ) : null;
		$jsonFrontParams = htmlspecialchars(json_encode($frontParams), ENT_QUOTES, 'UTF-8');
		$jsonFrontSystem = htmlspecialchars(json_encode($frontSystem), ENT_QUOTES, 'UTF-8');
		$jsonFrontTheme = htmlspecialchars(json_encode($theme), ENT_QUOTES, 'UTF-8');

		$this->registerChatbotScripts();
		return "<div class='mwai-chatbot-container' data-params='{$jsonFrontParams}' data-system='{$jsonFrontSystem}' data-theme='{$jsonFrontTheme}'></div>";
	}
	
}
