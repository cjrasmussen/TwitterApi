<?php
namespace cjrasmussen\TwitterApi;

use Exception;
use RuntimeException;

/**
 * Class for interacting with the Twitter API
 */
class TwitterApi
{
	public const AUTH_TYPE_BASIC = 1;
	public const AUTH_TYPE_BEARER = 2;
	public const AUTH_TYPE_OAUTH = 3;

	private $application_key;
	private $application_secret;
	private $user_token;
	private $user_secret;
	private $bearer_token;
	private $auth_type;
	private $oauth;
	private $args;

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
	public function auth($type, $token, $secret = null): void
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
	public function set_auth_type($type): void
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
	 * @return mixed|object
	 */
	public function request($type, $request, array $args = [], $body = null, $multipart = false)
	{
		$this->args = (is_array($args)) ? $args : [$args];
		$domain = (strpos($request, 'upload') !== false) ? 'https://upload.twitter.com/' : 'https://api.twitter.com/';
		$full_url = $base_url = $domain . $request;

		if ($multipart) {
			$type = 'POST';
		}

		if ($request === '/oauth/request_token') {
			// WE CAN'T HAVE USER DATA FOR THIS CALL, WILL NEED TO RE-AUTH
			unset($this->user_token);
			unset($this->user_secret);
			unset($this->oauth['oauth_token']);
		}

		if (($type === 'GET') AND (count($this->args))) {
			$full_url .= '?' . http_build_query($this->args);
		}

		if ($this->auth_type === self::AUTH_TYPE_OAUTH) {
			$base_string = $this->build_oauth_base_string($type, $base_url, $multipart);
			$signing_key = (rawurlencode($this->application_secret) . '&' . rawurlencode($this->user_secret));
			$this->oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
			$header = [$this->build_oauth_header(), 'Expect:'];
		} elseif ($this->auth_type === self::AUTH_TYPE_BEARER) {
			$header = ['Authorization: Bearer ' . $this->bearer_token];
		} else {
			$header = ['Authorization: Basic ' . base64_encode($this->application_key . ':' . $this->application_secret)];
		}

		$c = curl_init();
		curl_setopt($c, CURLOPT_HTTPHEADER, $header);
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
		curl_close($c);

		$return = json_decode($data, false);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('API response was not valid JSON');
		}

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
	private function build_oauth_base_string($type, $url, $multipart = false): string
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
			if ((is_string($val)) OR (is_int($val))) {
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
}
