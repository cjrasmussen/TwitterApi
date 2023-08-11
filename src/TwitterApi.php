<?php

namespace cjrasmussen\TwitterApi;

use Exception;
use JsonException;

/**
 * Class for interacting with the Twitter API
 */
class TwitterApi
{
	public const AUTH_TYPE_BASIC = 1;
	public const AUTH_TYPE_BEARER = 2;
	public const AUTH_TYPE_OAUTH = 3;

	private const TWITTER_API_URL_PRIMARY = 'https://api.twitter.com/';
	private const TWITTER_API_URL_UPLOAD = 'https://upload.twitter.com/';

	private string $application_key;
	private string $application_secret;
	private ?string $user_token;
	private ?string $user_secret;
	private ?string $bearer_token;
	private int $auth_type;
	private ?array $oauth;
	private ?array $args;

	public function __construct($application_key, $application_secret)
	{
		$this->application_key = $application_key;
		$this->application_secret = $application_secret;
		$this->auth_type = self::AUTH_TYPE_BASIC;
	}

	/**
	 * Set the credentials for use communicating to Twitter
	 *
	 * @param int $type
	 * @param string $token
	 * @param string|null $secret
	 * @throws Exception
	 */
	public function auth(int $type, string $token, ?string $secret = null): void
	{
		if ($type === self::AUTH_TYPE_OAUTH) {
			$this->user_token = $token;
			$this->user_secret = $secret;
			$this->oauth = [
				'oauth_consumer_key' => $this->application_key,
				'oauth_nonce' => $this->generate_nonce(),
				'oauth_signature_method' => 'HMAC-SHA1',
				'oauth_token' => $this->user_token,
				'oauth_timestamp' => time(),
				'oauth_version' => '1.0'
			];
		} elseif ($type === self::AUTH_TYPE_BEARER) {
			$this->bearer_token = $token;
		}

		$this->set_auth_type($type);
	}

	/**
	 * Set the authorization type
	 *
	 * @param int $type
	 */
	public function set_auth_type(int $type): void
	{
		$this->auth_type = $type;
	}

	/**
	 * Make a request to the Twitter API
	 *
	 * @param string $type
	 * @param string $request
	 * @param array $args
	 * @param string|null $body
	 * @param bool $multipart
	 * @param array $headers
	 * @return mixed|object
	 * @throws JsonException
	 */
	public function request(string $type, string $request, array $args = [], ?string $body = null, bool $multipart = false, array $headers = [])
	{
		$request = trim($request, ' /');
		$this->args = (is_array($args)) ? $args : [$args];
		$domain = (strpos($request, 'upload') !== false) ? self::TWITTER_API_URL_UPLOAD : self::TWITTER_API_URL_PRIMARY;
		$full_url = $base_url = $domain . $request;

		if ($multipart) {
			$type = 'POST';
		}

		$oauth_token_request = ($request === 'oauth/request_token');

		if ($oauth_token_request) {
			// WE CAN'T HAVE USER DATA FOR THIS CALL, WILL NEED TO RE-AUTH
			unset($this->user_token);
			unset($this->user_secret);
			unset($this->oauth['oauth_token']);
		}

		if (($type === 'GET') && (count($this->args))) {
			$full_url .= '?' . http_build_query($this->args);
		}

		if ($this->auth_type === self::AUTH_TYPE_OAUTH) {
			$base_string = $this->build_oauth_base_string($type, $base_url, $multipart);
			$signing_key = (rawurlencode($this->application_secret) . '&' . rawurlencode($this->user_secret));
			$this->oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
			$headers[] = $this->build_oauth_header();
			$headers[] = 'Expect:';
		} elseif ($this->auth_type === self::AUTH_TYPE_BEARER) {
			$headers[] = ['Authorization: Bearer ' . $this->bearer_token];
		} else {
			$headers[] = ['Authorization: Basic ' . base64_encode($this->application_key . ':' . $this->application_secret)];
		}

		$c = curl_init();
		curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($c, CURLOPT_HEADER, 0);
		curl_setopt($c, CURLOPT_VERBOSE, 0);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($c, CURLOPT_URL, $full_url);

		switch ($type) {
			case 'POST':
				curl_setopt($c, CURLOPT_POST, 1);
				break;
			case 'GET':
				curl_setopt($c, CURLOPT_HTTPGET, 1);
				break;
			default:
				curl_setopt($c, CURLOPT_CUSTOMREQUEST, $type);
		}

		if ($body) {
			curl_setopt($c, CURLOPT_POSTFIELDS, $body);
		} elseif (count($this->args)) {
			if ($multipart) {
				curl_setopt($c, CURLOPT_POSTFIELDS, $this->args);
			} elseif ($type !== 'GET') {
				curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($this->args));
			}
		}

		$data = curl_exec($c);

		if ($data) {
			if ($oauth_token_request) {
				$return = $this->parse_oauth_token_request_response($data);
			} else {
				$return = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
			}
		} else {
			// THE TWITTER API HAS A COUPLE CALLS THAT DON'T RETURN ANYTHING, RELYING INSTEAD ON THE HTTP RESPONSE CODE
			$return = (object)[
				'http_status' => curl_getinfo($c, CURLINFO_HTTP_CODE),
			];
		}

		curl_close($c);

		return $return;
	}

	/**
	 * Generate a nonce for a Twitter API request
	 *
	 * @return string
	 * @throws Exception
	 */
	private function generate_nonce(): string
	{
		$letters = range('A', 'z');
		$numbers = range(0, 9);
		$options = array_merge($letters, $numbers);

		$string = '';
		for ($n = 0; $n < 32; $n++) {
			$index = random_int(0, (count($options) - 1));
			$string .= $options[$index];
		}

		return base64_encode($string);
	}

	/**
	 * Build an oAuth base string
	 *
	 * @param string $type
	 * @param string $url
	 * @param bool $multipart
	 * @return string
	 */
	private function build_oauth_base_string(string $type, string $url, bool $multipart = false): string
	{
		$query_string = parse_url($url, PHP_URL_QUERY);
		parse_str($query_string, $query_string_args);

		if (count($query_string_args)) {
			[$url,] = explode('?', $url);
		}

		if ($multipart) {
			// IGNORE REQUEST VARIABLES IF IT'S MULTIPART
			$incoming = $this->oauth;
		} else {
			$incoming = array_merge($this->oauth, $this->args, $query_string_args);
		}

		ksort($incoming);

		$data = [];
		foreach ($incoming AS $key => $val) {
			if ((is_string($val)) || (is_int($val))) {
				$data[] = rawurlencode($key) . '=' . rawurlencode($val);
			}
		}

		$parameter_string = implode('&', $data);
		return strtoupper($type) . '&' . rawurlencode($url) . '&' . rawurlencode($parameter_string);
	}

	/**
	 * Build an oAuth header
	 *
	 * @return string
	 */
	private function build_oauth_header(): string
	{
		ksort($this->oauth);

		$data = [];
		foreach ($this->oauth as $key => $val) {
			$data[] = $key . '="' . rawurlencode($val) . '"';
		}

		return 'Authorization: OAuth ' . implode(', ', $data);
	}

	/**
	 * Parse the response string from an oAuth token request
	 *
	 * @param string $response
	 * @return object
	 */
	private function parse_oauth_token_request_response(string $response): object
	{
		$return = [];
		parse_str($response, $return);
		return (object)$return;
	}
}
