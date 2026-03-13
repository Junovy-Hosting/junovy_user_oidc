<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class KeycloakAdminService {

	private IClient $httpClient;
	private ?string $accessToken = null;
	private int $tokenExpiresAt = 0;

	public function __construct(
		IClientService $clientService,
		private LoggerInterface $logger,
		private string $keycloakBaseUrl,
		private string $clientId,
		private string $clientSecret,
	) {
		$this->httpClient = $clientService->newClient();
	}

	/**
	 * Fetch or return cached access token via client credentials grant.
	 *
	 * @throws \RuntimeException if token fetch fails
	 */
	public function getAccessToken(): string {
		if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
			return $this->accessToken;
		}

		$tokenUrl = $this->keycloakBaseUrl . '/protocol/openid-connect/token';

		$response = $this->httpClient->post($tokenUrl, [
			'body' => [
				'grant_type' => 'client_credentials',
				'client_id' => $this->clientId,
				'client_secret' => $this->clientSecret,
			],
		]);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException('Failed to fetch Keycloak access token: HTTP ' . $response->getStatusCode());
		}

		$data = json_decode($response->getBody(), true);
		$this->accessToken = $data['access_token'];
		// Refresh 30s before expiry to avoid edge cases
		$this->tokenExpiresAt = time() + ($data['expires_in'] ?? 300) - 30;

		return $this->accessToken;
	}

	/**
	 * Make an authenticated GET request to the Keycloak Admin API.
	 * Retries once on 401 with a fresh token.
	 */
	private function adminGet(string $path, array $query = []): array {
		$url = $this->getAdminUrl() . $path;
		$attempt = 0;

		while ($attempt < 2) {
			$response = $this->httpClient->get($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->getAccessToken()],
				'query' => $query,
			]);

			if ($response->getStatusCode() === 401 && $attempt === 0) {
				$this->accessToken = null;
				$attempt++;
				continue;
			}

			if ($response->getStatusCode() !== 200) {
				throw new \RuntimeException("Keycloak API error: HTTP {$response->getStatusCode()} for {$path}");
			}

			return json_decode($response->getBody(), true);
		}

		throw new \RuntimeException("Keycloak API auth failed after retry for {$path}");
	}

	/**
	 * Fetch all results from a paginated Keycloak endpoint.
	 */
	private function adminGetPaginated(string $path, array $query = []): array {
		$results = [];
		$pageSize = 100;
		$offset = 0;

		do {
			$page = $this->adminGet($path, array_merge($query, [
				'first' => $offset,
				'max' => $pageSize,
			]));
			$results = array_merge($results, $page);
			$offset += $pageSize;
		} while (count($page) === $pageSize);

		return $results;
	}

	/**
	 * Get all organizations in the realm with their attributes.
	 */
	public function getOrganizations(): array {
		return $this->adminGetPaginated('/organizations');
	}

	/**
	 * Get all members of an organization.
	 */
	public function getOrganizationMembers(string $orgId): array {
		return $this->adminGetPaginated('/organizations/' . urlencode($orgId) . '/members');
	}

	/**
	 * Get all groups in the realm with their attributes.
	 */
	public function getGroups(): array {
		return $this->adminGetPaginated('/groups');
	}

	/**
	 * Get all members of a group.
	 */
	public function getGroupMembers(string $groupId): array {
		return $this->adminGetPaginated('/groups/' . urlencode($groupId) . '/members');
	}

	/**
	 * Get a specific attribute value from a Keycloak entity's attributes array.
	 * Keycloak stores attributes as {"key": ["value1", "value2"]}.
	 *
	 * @return string|null First value of the attribute, or null if not set
	 */
	public static function getAttribute(array $entity, string $key): ?string {
		$attrs = $entity['attributes'] ?? [];
		if (isset($attrs[$key]) && is_array($attrs[$key]) && count($attrs[$key]) > 0) {
			return $attrs[$key][0];
		}
		return null;
	}

	private function getAdminUrl(): string {
		// keycloakBaseUrl is like http://keycloak.local/realms/dds
		// Admin API is at http://keycloak.local/admin/realms/dds
		$pos = strpos($this->keycloakBaseUrl, '/realms/');
		if ($pos === false) {
			throw new \RuntimeException('Invalid Keycloak base URL: missing /realms/ segment');
		}
		return substr($this->keycloakBaseUrl, 0, $pos) . '/admin' . substr($this->keycloakBaseUrl, $pos);
	}
}
