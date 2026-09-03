<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Helper;

use OCA\UserOIDC\Vendor\Id4me\RP\HttpClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class HttpClientHelper implements HttpClient {

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	public function get($url, array $headers = [], array $options = []) {
		// An explicit per-provider 'verify' option takes precedence over the global config
		if (!isset($options['verify']) && $this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}

		return $this->clientService->newClient()->get($url, $options)->getBody();
	}

	public function post($url, $body, array $headers = []) {
		$options = [
			'headers' => $headers,
			'body' => $body,
		];

		if ($this->shouldDisableSSLVerification()) {
			$options['verify'] = false;
		}

		return $this->clientService->newClient()->post($url, $options)->getBody();
	}

	private function shouldDisableSSLVerification(): bool {
		if ($this->config->getSystemValueBool('debug', false)) {
			return true;
		}

		$oidcConfig = $this->config->getSystemValue('junovy_user_oidc', []);
		if (!isset($oidcConfig['httpclient.allowselfsigned'])) {
			return false;
		}

		$allowSelfSigned = $oidcConfig['httpclient.allowselfsigned'];

		return !($allowSelfSigned === false
			|| $allowSelfSigned === 'false'
			|| $allowSelfSigned === 0
			|| $allowSelfSigned === '0');
	}

	/**
	 * POST request with additional options (e.g., TLS verify)
	 *
	 * @param string $url
	 * @param mixed $body
	 * @param array<string, mixed> $headers
	 * @param array{verify?: bool} $options Additional options like 'verify' for TLS
	 * @return string
	 */
	public function postWithOptions($url, $body, array $headers = [], array $options = []): string {
		$requestOptions = [
			'headers' => $headers,
			'body' => $body,
		];

		// An explicit per-provider 'verify' option takes precedence over the global config
		if (isset($options['verify'])) {
			$requestOptions['verify'] = $options['verify'];
		} elseif ($this->shouldDisableSSLVerification()) {
			$requestOptions['verify'] = false;
		}

		$body = $this->clientService->newClient()->post($url, $requestOptions)->getBody();
		if (is_resource($body)) {
			$contents = stream_get_contents($body);
			return $contents !== false ? $contents : '';
		}
		return $body;
	}
}
