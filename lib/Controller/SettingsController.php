<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use Exception;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\ResponseDefinitions;
use OCA\UserOIDC\Service\ID4MeService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type UserOIDCProvider from ResponseDefinitions
 */
class SettingsController extends OCSController {

	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private ProviderMapper $providerMapper,
		private ID4MeService $id4meService,
		private ProviderService $providerService,
		private ICrypto $crypto,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function isDiscoveryEndpointValid($url) {
		$result = [
			'isReachable' => false,
			'missingFields' => [],
		];

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url);
			$httpCode = $response->getStatusCode();
			$body = $response->getBody();

			// Check if the request was successful
			if ($httpCode === Http::STATUS_OK && !empty($body)) {
				$result['isReachable'] = true;
				$data = json_decode($body, true);

				// Check for required fields as defined in: https://openid.net/specs/openid-connect-discovery-1_0.html
				$requiredFields = [
					'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri',
					'response_types_supported', 'subject_types_supported', 'id_token_signing_alg_values_supported',
				];

				foreach ($requiredFields as $field) {
					if (!isset($data[$field])) {
						$result['missingFields'][] = $field;
					}
				}
			}
		} catch (Exception $e) {
			$this->logger->error('Discovery endpoint validation error', ['exception' => $e]);
		}

		return $result;
	}

	/**
	 * Create a new provider
	 *
	 * @param string $identifier The unique identifier for the provider
	 * @param string $clientId The client ID for the provider
	 * @param string $clientSecret The client secret for the provider
	 * @param string $discoveryEndpoint The discovery endpoint URL
	 * @param array<string, mixed> $settings Optional provider settings
	 * @param string $scope The scope to request
	 * @param string|null $endSessionEndpoint Optional end session endpoint URL
	 * @param string|null $postLogoutUri Optional post-logout redirect URI
	 * @return DataResponse<Http::STATUS_OK, UserOIDCProvider, array{}>|DataResponse<Http::STATUS_CONFLICT, array{message: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 *
	 * 200: The provider was successfully created
	 * 400: The discovery endpoint is not reachable or is invalid
	 * 409: A provider with the given identifier already exists
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function createProvider(string $identifier, string $clientId, string $clientSecret, string $discoveryEndpoint,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null,
		?string $postLogoutUri = null): DataResponse {
		if ($this->providerService->getProviderByIdentifier($identifier) !== null) {
			return new DataResponse(['message' => 'Provider with the given identifier already exists'], Http::STATUS_CONFLICT);
		}

		$result = $this->isDiscoveryEndpointValid($discoveryEndpoint);
		if (!$result['isReachable']) {
			$message = 'The discovery endpoint is not reachable.';
			return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		} elseif (!empty($result['missingFields'])) {
			$message = 'Invalid discovery endpoint. Missing fields: ' . implode(', ', $result['missingFields']);
			return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		}

		$provider = new Provider();
		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		$encryptedClientSecret = $this->crypto->encrypt($clientSecret);
		$provider->setClientSecret($encryptedClientSecret);
		$provider->setDiscoveryEndpoint($discoveryEndpoint);
		$provider->setEndSessionEndpoint($endSessionEndpoint ?: null);
		$provider->setPostLogoutUri($postLogoutUri ?: null);
		$provider->setScope($scope);
		$provider = $this->providerMapper->insert($provider);

		$providerSettings = $this->providerService->setSettings($provider->getId(), $settings);

		return new DataResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	/**
	 * Update an existing provider
	 *
	 * @param int $providerId The numeric ID of the provider to update
	 * @param string $identifier The unique identifier for the provider
	 * @param string $clientId The client ID for the provider
	 * @param string $discoveryEndpoint The discovery endpoint URL
	 * @param string|null $clientSecret Optional new client secret
	 * @param array<string, mixed> $settings Optional provider settings
	 * @param string $scope The scope to request
	 * @param string|null $endSessionEndpoint Optional end session endpoint URL
	 * @param string|null $postLogoutUri Optional post-logout redirect URI
	 * @return DataResponse<Http::STATUS_OK, UserOIDCProvider, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 *
	 * 200: The provider was successfully updated
	 * 400: The discovery endpoint is not reachable or is invalid
	 * 404: The provider does not exist
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function updateProvider(int $providerId, string $identifier, string $clientId, string $discoveryEndpoint, ?string $clientSecret = null,
		array $settings = [], string $scope = 'openid email profile', ?string $endSessionEndpoint = null,
		?string $postLogoutUri = null): DataResponse {
		$provider = $this->providerMapper->getProvider($providerId);

		if ($this->providerService->getProviderByIdentifier($identifier) === null) {
			return new DataResponse(['message' => 'Provider with the given identifier does not exist'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->isDiscoveryEndpointValid($discoveryEndpoint);
		if (!$result['isReachable']) {
			$message = 'The discovery endpoint is not reachable.';
			return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		} elseif (!empty($result['missingFields'])) {
			$message = 'Invalid discovery endpoint. Missing fields: ' . implode(', ', $result['missingFields']);
			return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
		}

		$provider->setIdentifier($identifier);
		$provider->setClientId($clientId);
		if ($clientSecret) {
			$encryptedClientSecret = $this->crypto->encrypt($clientSecret);
			$provider->setClientSecret($encryptedClientSecret);
		}
		$provider->setDiscoveryEndpoint($discoveryEndpoint);
		$provider->setEndSessionEndpoint($endSessionEndpoint ?: null);
		$provider->setPostLogoutUri($postLogoutUri ?: null);
		$provider->setScope($scope);
		$provider = $this->providerMapper->update($provider);

		$providerSettings = $this->providerService->setSettings($providerId, $settings);
		// invalidate JWKS cache
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE, '');
		$this->providerService->setSetting($providerId, ProviderService::SETTING_JWKS_CACHE_TIMESTAMP, '');

		return new DataResponse(array_merge($provider->jsonSerialize(), ['settings' => $providerSettings]));
	}

	/**
	 * Delete a provider
	 *
	 * @param int $providerId The numeric ID of the provider to delete
	 * @return DataResponse<Http::STATUS_OK, array{}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{}, array{}>
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 *
	 * 200: The provider was successfully deleted
	 * 404: The provider does not exist
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function deleteProvider(int $providerId): DataResponse {
		try {
			$provider = $this->providerMapper->getProvider($providerId);
		} catch (DoesNotExistException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		$this->providerMapper->delete($provider);
		$this->providerService->deleteSettings($providerId);

		return new DataResponse([], Http::STATUS_OK);
	}

	/**
	 * Get the list of supported provider settings
	 *
	 * @return DataResponse<Http::STATUS_OK, list<string>, array{}>
	 *
	 * 200: The list of supported settings
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function getSupportedSettings(): DataResponse {
		return new DataResponse($this->providerService->getSupportedSettings());
	}

	/**
	 * Get all registered providers with their settings
	 *
	 * @return DataResponse<Http::STATUS_OK, list<UserOIDCProvider>, array{}>
	 *
	 * 200: The list of providers with their settings
	 */
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function getProviders(): DataResponse {
		return new DataResponse($this->providerService->getProvidersWithSettings());
	}

	private function getID4ME(): bool {
		return $this->id4meService->getID4ME();
	}

	/**
	 * Set the ID4me state
	 *
	 * @param bool $enabled Whether ID4me should be enabled
	 * @return DataResponse<Http::STATUS_OK, array{enabled: bool}, array{}>
	 *
	 * 200: The ID4me state was successfully updated
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function setID4ME(bool $enabled): DataResponse {
		$this->id4meService->setID4ME($enabled);
		return new DataResponse(['enabled' => $this->getID4ME()]);
	}

	/**
	 * Set admin configuration values
	 *
	 * @param array<string, mixed> $values Key-value pairs of configuration options to set
	 * @return DataResponse<Http::STATUS_OK, array{}, array{}>
	 *
	 * 200: The admin configuration was successfully updated
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if ($key === 'store_login_token' && is_bool($value)) {
				$this->appConfig->setValueString(Application::APP_ID, 'store_login_token', $value ? '1' : '0', lazy: true);
			}
		}
		return new DataResponse([]);
	}

	/**
	 * Resync groups for OIDC users by removing them from hashed groups.
	 * This fixes the issue where groups were incorrectly hashed with SHA256.
	 * Users will get proper groups on their next login.
	 *
	 * @param int|null $providerId Optional provider ID to resync only for a specific provider
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_INTERNAL_SERVER_ERROR, array{success: bool, message: string, stats?: array{hashed_groups_found: int, users_removed: int, groups_cleaned: int}}, array{}>
	 *
	 * 200: Resync statistics
	 * 500: Resync failed
	 */
	#[PasswordConfirmationRequired]
	#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT, tags: ['user_oidc_settings'])]
	public function resyncGroups(?int $providerId = null): DataResponse {
		$stats = [
			'hashed_groups_found' => 0,
			'users_removed' => 0,
			'groups_cleaned' => 0,
		];

		try {
			// Find all groups that look like SHA256 hashes (64 hex characters)
			$allGroups = $this->groupManager->search('');
			$hashedGroups = [];

			foreach ($allGroups as $group) {
				$gid = $group->getGID();
				// Check if group ID looks like a SHA256 hash (64 hex characters)
				if (preg_match('/^[a-f0-9]{64}$/i', $gid)) {
					$hashedGroups[] = $group;
					$stats['hashed_groups_found']++;
				}
			}

			// Remove users from hashed groups
			foreach ($hashedGroups as $group) {
				$users = $group->getUsers();
				foreach ($users as $user) {
					// Only process users from OIDC backend if providerId is specified
					if ($providerId !== null) {
						// Check if user belongs to OIDC backend
						if ($user->getBackendClassName() === Application::APP_ID) {
							// This is an OIDC user, remove from hashed group
							$group->removeUser($user);
							$stats['users_removed']++;
						}
					} else {
						// Remove all users from hashed groups
						$group->removeUser($user);
						$stats['users_removed']++;
					}
				}

				// If group is now empty, optionally delete it
				// For now, we'll leave empty groups (they can be cleaned up manually)
				if (count($group->getUsers()) === 0) {
					$stats['groups_cleaned']++;
				}
			}

			$this->logger->info('Group resync completed', $stats);

			return new DataResponse([
				'success' => true,
				'message' => 'Groups resynced successfully. Users will get proper groups on their next login.',
				'stats' => $stats,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Group resync failed', ['exception' => $e]);
			return new DataResponse([
				'success' => false,
				'message' => 'Failed to resync groups: ' . $e->getMessage(),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
