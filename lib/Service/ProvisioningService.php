<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use InvalidArgumentException;
use Locale;
use OC\Accounts\AccountManager;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Image;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class ProvisioningService {

	public function __construct(
		private LocalIdService $idService,
		private ProviderService $providerService,
		private UserMapper $userMapper,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private IAccountManager $accountManager,
		private IClientService $clientService,
		private IAvatarManager $avatarManager,
		private IConfig $config,
		private ISession $session,
		private IFactory $l10nFactory,
		private ProviderMapper $providerMapper,
		private ICrypto $crypto,
		private ?CirclesService $circlesService = null,
	) {
	}

	/**
	 * Login-time group and team provisioning was disabled in JUN-836: it is handled by the
	 * junovy-cloud-provisioner service instead. It can be re-enabled per installation with
	 * 'junovy_user_oidc' => ['login_time_provisioning' => true] in config.php.
	 */
	public function isLoginTimeProvisioningEnabled(): bool {
		$oidcSystemConfig = $this->config->getSystemValue(Application::APP_ID, []);
		return isset($oidcSystemConfig['login_time_provisioning'])
			&& in_array($oidcSystemConfig['login_time_provisioning'], [true, 'true', 1, '1'], true);
	}

	public function hasOidcUserProvisitioned(string $userId): bool {
		try {
			$this->userMapper->getUser($userId);
			return true;
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
		}
		return false;
	}

	/**
	 * Resolves a claim path like "custom.nickname" or multiple alternatives separated by "|".
	 * Returns the first found value, or null if none could be resolved.
	 */
	public function getClaimValues(object|array $tokenPayload, string $claimPath, int $providerId): mixed {
		if ($claimPath === '') {
			return null;
		}

		// Check config if dot-notation resolution is enabled
		$resolveDot = $this->providerService->getSetting($providerId, ProviderService::SETTING_RESOLVE_NESTED_AND_FALLBACK_CLAIMS_MAPPING, '0') === '1';

		if (!$resolveDot) {
			// fallback to simple access
			if (is_object($tokenPayload) && property_exists($tokenPayload, $claimPath)) {
				return $tokenPayload->{$claimPath};
			} elseif (is_array($tokenPayload) && array_key_exists($claimPath, $tokenPayload)) {
				return $tokenPayload[$claimPath];
			}
			return null;
		}

		// Support alternatives separated by "|"
		$alternatives = explode('|', $claimPath);

		foreach ($alternatives as $altPath) {
			$result = $this->resolveNestedClaim($tokenPayload, trim($altPath));
			if ($result !== null) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Resolves a claim path like "custom.nickname" or multiple alternatives separated by "|".
	 * Returns the first found string value, or null if none could be resolved.
	 */
	public function getClaimValue(object|array $tokenPayload, string $claimPath, int $providerId): mixed {
		$value = $this->getClaimValues($tokenPayload, $claimPath, $providerId);
		return is_string($value) ? $value : null;
	}

	/**
	 * Resolves a claim path against a token payload using greedy longest-prefix matching.
	 *
	 * Instead of splitting on every dot (which breaks URL-based claim names like
	 * "https://idp.example.com/claims/groups" or literal dot keys like "user.role"),
	 * this method first tries the full path as a literal key, then progressively
	 * shorter dot-delimited prefixes (longest first), recursing into the remainder.
	 */
	private function resolveNestedClaim(object|array $data, string $path): mixed {
		if ($path === '') {
			return null;
		}

		// Try full path as literal key
		if (is_object($data) && property_exists($data, $path)) {
			return $data->{$path};
		} elseif (is_array($data) && array_key_exists($path, $data)) {
			return $data[$path];
		}

		// Try progressively shorter dot-prefixes (longest first)
		$lastDot = strlen($path);
		while (($lastDot = strrpos($path, '.', -(strlen($path) - $lastDot + 1))) !== false) {
			$prefix = substr($path, 0, $lastDot);
			$remainder = substr($path, $lastDot + 1);

			$prefixValue = null;
			if (is_object($data) && property_exists($data, $prefix)) {
				$prefixValue = $data->{$prefix};
			} elseif (is_array($data) && array_key_exists($prefix, $data)) {
				$prefixValue = $data[$prefix];
			}

			if ($prefixValue !== null && (is_object($prefixValue) || is_array($prefixValue))) {
				$result = $this->resolveNestedClaim($prefixValue, $remainder);
				if ($result !== null) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * @param string $tokenUserId
	 * @param int $providerId
	 * @param object $idTokenPayload
	 * @param IUser|null $existingLocalUser
	 * @return array{user: ?IUser, userData: array}
	 * @throws Exception
	 * @throws PropertyDoesNotExistException
	 * @throws PreConditionNotMetException
	 */
	public function provisionUser(string $tokenUserId, int $providerId, object $idTokenPayload, ?IUser $existingLocalUser = null): array {
		// user data potentially later used by globalsiteselector if user_oidc is used with global scale
		$oidcGssUserData = get_object_vars($idTokenPayload);

		// get name/email/quota information from the token itself
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$email = $this->getClaimValue($idTokenPayload, $emailAttribute, $providerId);//$idTokenPayload->{$emailAttribute} ?? null;

		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$userName = $this->getClaimValue($idTokenPayload, $displaynameAttribute, $providerId);//$idTokenPayload->{$displaynameAttribute} ?? null;

		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');
		$quota = $this->getClaimValue($idTokenPayload, $quotaAttribute, $providerId);//$idTokenPayload->{$quotaAttribute} ?? null;

		$languageAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LANGUAGE, 'language');
		$language = $this->getClaimValue($idTokenPayload, $languageAttribute, $providerId);//$idTokenPayload->{$languageAttribute} ?? null;

		$localeAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LOCALE, 'locale');
		$locale = $this->getClaimValue($idTokenPayload, $localeAttribute, $providerId);

		$genderAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GENDER, 'gender');
		$gender = $this->getClaimValue($idTokenPayload, $genderAttribute, $providerId);//$idTokenPayload->{$genderAttribute} ?? null;

		$addressAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ADDRESS, 'address');
		$address = $this->getClaimValue($idTokenPayload, $addressAttribute, $providerId);//$idTokenPayload->{$addressAttribute} ?? null;

		$postalcodeAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_POSTALCODE, 'postal_code');
		$postalcode = $this->getClaimValue($idTokenPayload, $postalcodeAttribute, $providerId);//$idTokenPayload->{$postalcodeAttribute} ?? null;

		$streetAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_STREETADDRESS, 'street_address');
		$street = $this->getClaimValue($idTokenPayload, $streetAttribute, $providerId);//$idTokenPayload->{$streetAttribute} ?? null;

		$localityAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_LOCALITY, 'locality');
		$locality = $this->getClaimValue($idTokenPayload, $localityAttribute, $providerId);//$idTokenPayload->{$localityAttribute} ?? null;

		$regionAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_REGION, 'region');
		$region = $this->getClaimValue($idTokenPayload, $regionAttribute, $providerId);//$idTokenPayload->{$regionAttribute} ?? null;

		$countryAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_COUNTRY, 'country');
		$country = $this->getClaimValue($idTokenPayload, $countryAttribute, $providerId);//$idTokenPayload->{$countryAttribute} ?? null;

		$websiteAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_WEBSITE, 'website');
		$website = $this->getClaimValue($idTokenPayload, $websiteAttribute, $providerId);//$idTokenPayload->{$websiteAttribute} ?? null;

		$avatarAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_AVATAR, 'avatar');
		$avatar = $this->getClaimValue($idTokenPayload, $avatarAttribute, $providerId);//$idTokenPayload->{$avatarAttribute} ?? null;

		$phoneAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_PHONE, 'phone_number');
		$phone = $this->getClaimValue($idTokenPayload, $phoneAttribute, $providerId);//$idTokenPayload->{$phoneAttribute} ?? null;

		$twitterAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_TWITTER, 'twitter');
		$twitter = $this->getClaimValue($idTokenPayload, $twitterAttribute, $providerId);//$idTokenPayload->{$twitterAttribute} ?? null;

		$fediverseAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_FEDIVERSE, 'fediverse');
		$fediverse = $this->getClaimValue($idTokenPayload, $fediverseAttribute, $providerId);//$idTokenPayload->{$fediverseAttribute} ?? null;

		$organisationAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ORGANISATION, 'organisation');
		$organisation = $this->getClaimValue($idTokenPayload, $organisationAttribute, $providerId);//$idTokenPayload->{$organisationAttribute} ?? null;

		$roleAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ROLE, 'role');
		$role = $this->getClaimValue($idTokenPayload, $roleAttribute, $providerId);//$idTokenPayload->{$roleAttribute} ?? null;

		$headlineAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_HEADLINE, 'headline');
		$headline = $this->getClaimValue($idTokenPayload, $headlineAttribute, $providerId);//$idTokenPayload->{$headlineAttribute} ?? null;

		$biographyAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_BIOGRAPHY, 'biography');
		$biography = $this->getClaimValue($idTokenPayload, $biographyAttribute, $providerId);//$idTokenPayload->{$biographyAttribute} ?? null;

		$pronounsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_PRONOUNS, 'pronouns');
		$pronouns = $idTokenPayload->{$pronounsAttribute} ?? null;

		$birthdateAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_BIRTHDATE, 'birthdate');
		$birthdate = $idTokenPayload->{$birthdateAttribute} ?? null;

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_UID, $idTokenPayload, $tokenUserId);
		$this->eventDispatcher->dispatchTyped($event);

		// use an existing user (from another backend) when soft auto provisioning is enabled
		if ($existingLocalUser !== null) {
			$user = $existingLocalUser;
		} else {
			// if disable_account_creation is true, user_oidc should not create any user
			// so we just exit
			// but it will accept connection from users it might have created in the past (before disable_account_creation was enabled)
			$oidcSystemConfig = $this->config->getSystemValue('junovy_user_oidc', []);
			$isUserCreationDisabled = isset($oidcSystemConfig['disable_account_creation'])
				&& in_array($oidcSystemConfig['disable_account_creation'], [true, 'true', 1, '1'], true);
			if ($isUserCreationDisabled) {
				return [
					'user' => null,
					'userData' => $oidcGssUserData,
				];
			}

			$backendUser = $this->userMapper->getOrCreate($providerId, $event->getValue() ?? '');
			$this->logger->debug('User obtained from the OIDC user backend: ' . $backendUser->getUserId());

			$user = $this->userManager->get($backendUser->getUserId());
			if ($user === null) {
				return [
					'user' => null,
					'userData' => $oidcGssUserData,
				];
			}
		}

		$account = $this->accountManager->getAccount($user);
		$fallbackScope = IAccountManager::SCOPE_LOCAL;
		$defaultScopes = array_merge(
			AccountManager::DEFAULT_SCOPES,
			$this->config->getSystemValue('account_manager.default_property_scope', []) ?? []
		);

		// Update displayname
		if (isset($userName)) {
			$newDisplayName = mb_substr($userName, 0, 255);
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload, $newDisplayName);
		} else {
			$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $idTokenPayload);
		}
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Displayname mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$displaynameAttribute] = $event->getValue();
			$newDisplayName = $event->getValue();
			if ($existingLocalUser === null) {
				$oldDisplayName = $backendUser->getDisplayName();
				if ($newDisplayName !== $oldDisplayName) {
					$backendUser->setDisplayName($newDisplayName);
					$this->userMapper->update($backendUser);
				}
				// 2 reasons why we should update the display name: It does not match the one
				// - of our backend
				// - returned by the user manager (outdated one before the fix in https://github.com/nextcloud/user_oidc/pull/530)
				if ($newDisplayName !== $oldDisplayName || $newDisplayName !== $user->getDisplayName()) {
					$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayName', $newDisplayName, $oldDisplayName));
				}
			} else {
				$oldDisplayName = $user->getDisplayName();
				if ($newDisplayName !== $oldDisplayName) {
					$user->setDisplayName($newDisplayName);
					if ($user->getBackendClassName() === Application::APP_ID) {
						$backendUser = $this->userMapper->getOrCreate($providerId, $user->getUID());
						$backendUser->setDisplayName($newDisplayName);
						$this->userMapper->update($backendUser);
					}
					$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayName', $newDisplayName, $oldDisplayName));
				}
			}
		}

		// Update e-mail
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_EMAIL, $idTokenPayload, $email);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Email mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$emailAttribute] = $event->getValue();
			$user->setSystemEMailAddress($event->getValue());
		}

		// Update the quota
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $idTokenPayload, $quota);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Quota mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$oidcGssUserData[$quotaAttribute] = $event->getValue();
			$user->setQuota($event->getValue());
		}

		// Update groups
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_GROUP_PROVISIONING, '0') === '1') {
			$groups = $this->provisionUserGroups($user, $providerId, $idTokenPayload);
			// for gss
			if ($groups !== null) {
				$groupIds = array_map(static function ($group) {
					return $group->gid;
				}, $groups);
				$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
				$oidcGssUserData[$groupsAttribute] = $groupIds;
			}
		}

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_LOCALE, $idTokenPayload, $locale);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Locale mapping event dispatched');
		if ($event->hasValue()) {
			$locale = $event->getValue();
			// according to the RFC, the locale we get is in BCP47 format (for example, en-US or fr-CA)
			// see https://openid.net/specs/openid-connect-core-1_0.html#StandardClaims
			$locale = Locale::canonicalize($locale);
			$locales = $this->l10nFactory->findAvailableLocales();
			$localeCodes = array_map(static function ($l) {
				return $l['code'];
			}, $locales);
			if (in_array($locale, $localeCodes, true) || $locale === 'en') {
				$this->config->setUserValue($user->getUID(), 'core', 'locale', $locale);
			} else {
				$this->logger->debug('Invalid locale in ID token', ['locale' => $locale]);
			}
		}

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_LANGUAGE, $idTokenPayload, $language);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Language mapping event dispatched');
		if ($event->hasValue()) {
			$language = $event->getValue();
			$languagesCodes = $this->l10nFactory->findAvailableLanguages();
			if (in_array($language, $languagesCodes, true) || $language === 'en') {
				$this->config->setUserValue($user->getUID(), 'core', 'lang', $language);
			} else {
				$this->logger->debug('Invalid language in ID token', ['language' => $language]);
			}
		}

		$addressParts = null;
		if (is_object($address)) {
			// Update the location/address
			$addressArray = json_decode(json_encode($address), true);
			if (is_array($addressArray)
				&& (isset($addressArray[$streetAttribute]) || isset($addressArray[$postalcodeAttribute]) || isset($addressArray[$localityAttribute])
				|| isset($addressArray[$regionAttribute]) || isset($addressArray[$countryAttribute]))
			) {
				$addressParts = [
					$addressArray[$streetAttribute] ?? '',
					($addressArray[$postalcodeAttribute] ?? '') . ' ' . ($addressArray[$localityAttribute] ?? ''),
					$addressArray[$regionAttribute] ?? '',
					$addressArray[$countryAttribute] ?? '',
				];
			} else {
				$address = null;
			}
		} elseif ($street !== null || $postalcode !== null || $locality !== null || $region !== null || $country !== null) {
			// Concatenate the address components
			$addressParts = [
				$street ?? '',
				($postalcode ?? '') . ' ' . ($locality ?? ''),
				$region ?? '',
				$country ?? '',
			];
		}

		if ($addressParts !== null) {
			// concatenate them back together into a string and remove unused ', '
			$address = str_replace('  ', ' ', implode(', ', $addressParts));
		}

		// Update the avatar
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_AVATAR, $idTokenPayload, $avatar);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Avatar mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$this->setUserAvatar($user->getUID(), $event->getValue());
		}

		// Update the gender
		// Since until now there is no default for property for gender we have to use default
		// In v31 there will be introduced PRONOUNS, which could be of better use
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GENDER, $idTokenPayload, $gender);
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Gender mapping event dispatched');
		if ($event->hasValue() && $event->getValue() !== null && $event->getValue() !== '') {
			$account->setProperty('gender', $event->getValue(), $fallbackScope, IAccountManager::VERIFIED, '');
		}

		$simpleAccountPropertyAttributes = [
			IAccountManager::PROPERTY_PHONE => ['value' => $phone, 'setting_key' => ProviderService::SETTING_MAPPING_PHONE],
			IAccountManager::PROPERTY_ADDRESS => ['value' => $address, 'setting_key' => ProviderService::SETTING_MAPPING_PHONE],
			IAccountManager::PROPERTY_WEBSITE => ['value' => $website, 'setting_key' => ProviderService::SETTING_MAPPING_WEBSITE],
			IAccountManager::PROPERTY_TWITTER => ['value' => $twitter, 'setting_key' => ProviderService::SETTING_MAPPING_TWITTER],
			IAccountManager::PROPERTY_FEDIVERSE => ['value' => $fediverse, 'setting_key' => ProviderService::SETTING_MAPPING_FEDIVERSE],
			IAccountManager::PROPERTY_ORGANISATION => ['value' => $organisation, 'setting_key' => ProviderService::SETTING_MAPPING_ORGANISATION],
			IAccountManager::PROPERTY_ROLE => ['value' => $role, 'setting_key' => ProviderService::SETTING_MAPPING_ROLE],
			IAccountManager::PROPERTY_HEADLINE => ['value' => $headline, 'setting_key' => ProviderService::SETTING_MAPPING_HEADLINE],
			IAccountManager::PROPERTY_BIOGRAPHY => ['value' => $biography, 'setting_key' => ProviderService::SETTING_MAPPING_BIOGRAPHY],
		];
		// properties that appeared after 28 (our min supported NC version)
		if (defined(IAccountManager::class . '::PROPERTY_PRONOUNS')) {
			$simpleAccountPropertyAttributes[IAccountManager::PROPERTY_PRONOUNS] = ['value' => $pronouns, 'setting_key' => ProviderService::SETTING_MAPPING_PRONOUNS];
		}
		if (defined(IAccountManager::class . '::PROPERTY_BIRTHDATE')) {
			$simpleAccountPropertyAttributes[IAccountManager::PROPERTY_BIRTHDATE] = ['value' => $birthdate, 'setting_key' => ProviderService::SETTING_MAPPING_BIRTHDATE];
		}

		foreach ($simpleAccountPropertyAttributes as $property => $values) {
			$event = new AttributeMappedEvent($values['setting_key'], $idTokenPayload, $values['value']);
			$this->eventDispatcher->dispatchTyped($event);
			$this->logger->debug($property . ' mapping event dispatched');
			if ($event->hasValue()) {
				$account->setProperty($property, $event->getValue(), $defaultScopes[$property] ?? $fallbackScope, IAccountManager::VERIFIED, '');
			}
		}

		while (true) {
			try {
				$this->accountManager->updateAccount($account);
				break;
			} catch (InvalidArgumentException $e) {
				// If the message is a property name, then this was throws because of an invalid property value
				if (in_array($e->getMessage(), IAccountManager::ALLOWED_PROPERTIES)) {
					$property = $account->getProperty($e->getMessage());
					// Remove the property from account
					$account->setProperty($property->getName(), '', $property->getScope(), IAccountManager::NOT_VERIFIED);
					$this->logger->info('Invalid account property provisioned', ['account' => $user->getUID(), 'property' => $property->getName()]);
					continue;
				}
				// unrelated error - rethrow
				throw $e;
			}
		}
		return [
			'user' => $user,
			'userData' => $oidcGssUserData,
		];
	}

	/**
	 * @param string $userId
	 * @param string $avatarAttribute
	 * @return void
	 */
	private function setUserAvatar(string $userId, string $avatarAttribute): void {
		$avatarContent = null;
		if (filter_var($avatarAttribute, FILTER_VALIDATE_URL)) {
			$client = $this->clientService->newClient();
			try {
				$response = $client->get($avatarAttribute);
				$ct = $response->getHeader('Content-Type');

				/** @psalm-suppress TypeDoesNotContainType */
				if (is_array($ct)) {
					$contentType = $ct[0] ?? '';
				} else {
					/** @psalm-suppress RedundantCast */
					$contentType = (string)$ct;
				}

				$contentType = strtolower(trim(explode(';', $contentType)[0]));

				if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/gif'], true)) {
					$this->logger->warning('Avatar response is not an image', ['content_type' => $contentType]);
					return;
				}
				$avatarContent = $response->getBody();
				if (is_resource($avatarContent)) {
					$avatarContent = stream_get_contents($avatarContent);
				}
				if ($avatarContent === false) {
					$this->logger->warning('Failed to read remote avatar response for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
					return;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Failed to get remote avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		} elseif (str_starts_with($avatarAttribute, 'data:image/png;base64,')) {
			$avatarContent = base64_decode(str_replace('data:image/png;base64,', '', $avatarAttribute));
			if ($avatarContent === false) {
				$this->logger->warning('Failed to decode base64 PNG avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		} elseif (str_starts_with($avatarAttribute, 'data:image/jpeg;base64,')) {
			$avatarContent = base64_decode(str_replace('data:image/jpeg;base64,', '', $avatarAttribute));
			if ($avatarContent === false) {
				$this->logger->warning('Failed to decode base64 JPEG avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		} else {
			// fallback if it's not a URL and does not have a base64 data prefix: try to decode it as base64
			$avatarContent = base64_decode($avatarAttribute);
			if ($avatarContent === false) {
				$this->logger->warning('Failed to decode base64 unprefixed avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
				return;
			}
		}

		if ($avatarContent === null || $avatarContent === '') {
			$this->logger->warning('Failed to set avatar for user ' . $userId, ['avatar_attribute' => $avatarAttribute]);
			return;
		}

		try {
			// inspired from OC\Core\Controller\AvatarController::postAvatar()
			$image = new Image();
			$image->loadFromData($avatarContent);
			$image->readExif($avatarContent);
			$image->fixOrientation();

			if ($image->valid()) {
				$mimeType = $image->mimeType();
				if ($mimeType !== 'image/jpeg' && $mimeType !== 'image/png') {
					$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Unknown filetype']);
					return;
				}

				if ($image->width() === $image->height()) {
					try {
						$avatar = $this->avatarManager->getAvatar($userId);
						$avatar->set($image);
						return;
					} catch (Throwable $e) {
						$this->logger->error('Failed to set remote avatar for user ' . $userId, ['exception' => $e]);
						return;
					}
				}
				$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Image is not square']);
			} else {
				$this->logger->warning('Failed to set remote avatar for user ' . $userId, ['error' => 'Invalid image']);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to set remote avatar for user ' . $userId, ['exception' => $e]);
		}
	}

	public function getSyncGroupsOfToken(int $providerId, object $idTokenPayload): ?array {
		$groupsAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_GROUPS, 'groups');
		$groupsData = $this->getClaimValues($idTokenPayload, $groupsAttribute, $providerId);

		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_GROUPS, $idTokenPayload, json_encode($groupsData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Group mapping event dispatched');

		if ($event->hasValue() && $event->getValue() !== null) {
			// casted to null if empty value
			$groups = json_decode($event->getValue() ?? '');
			// support values like group1,group2
			if (is_string($groups)) {
				$groups = explode(',', $groups);
				// remove surrounding spaces in each group
				$groups = array_map('trim', $groups);
				// remove empty strings
				$groups = array_filter($groups);
			}
			$syncGroups = [];

			$token = null;
			$tenant = null;
			if ($this->providerService->getSetting($providerId, ProviderService::SETTING_AZURE_GROUP_NAMES, '0') === '1') {
				$azureGroupSyncContext = $this->getAzureGroupSyncContext($providerId);
				if ($azureGroupSyncContext === null) {
					return null;
				}

				$tenant = $azureGroupSyncContext['tenant'];
				$token = $azureGroupSyncContext['token'];
			}

			foreach ($groups as $k => $v) {
				if (is_object($v)) {
					// Handle array of objects, e.g. [{gid: "1", displayName: "group1"}, ...]
					if (empty($v->gid) && $v->gid !== '0' && $v->gid !== 0) {
						continue;
					}
					$group = $v;
				} elseif (is_string($v)) {
					// Handle array of strings, e.g. ["group1", "group2", ...]
					$group = (object)['gid' => $v, 'displayName' => $v];
				} else {
					continue;
				}

				if ($groupsWhitelistRegex) {
					$matchResult = preg_match($groupsWhitelistRegex, $group->gid);
					if ($matchResult !== 1) {
						if ($matchResult === 0) {
							$this->logger->debug('Skipped group `' . $group->gid . '` for importing as not part of whitelist (not matching the regex)');
						} else {
							$this->logger->debug('Skipped group `' . $group->gid . '` for importing as not part of whitelist (failure when matching)', ['match_result' => $matchResult]);
						}
						continue;
					}
				}

				if ($this->providerService->getSetting($providerId, ProviderService::SETTING_AZURE_GROUP_NAMES, '0') === '1' && is_string($v)) {
					$group = $this->getAzureGroupWithResolvedName($providerId, $tenant, $token, $v);
					if ($group === null) {
						continue;
					}
				}
				// Note: Do NOT hash group IDs like user IDs (upstream calls idService->getId() here).
				// Group names from the IdP should be used as-is to maintain readability and proper
				// group membership. Hashing group names with SHA256 when SETTING_UNIQUE_UID is
				// enabled produced unreadable groups.

				$syncGroups[] = $group;
			}

			return $syncGroups;
		}

		return null;
	}

	/**
	 * @return array{tenant: string, token: string}|null
	 */
	private function getAzureGroupSyncContext(int $providerId): ?array {
		$provider = $this->providerMapper->getProvider($providerId);
		$url = $provider->getDiscoveryEndpoint();
		$tenant = explode('//', $url);
		$tenant = count($tenant) === 1 ? $tenant[0] : $tenant[1];
		$tenant = explode('/', $tenant);
		if (count($tenant) === 1) {
			$this->logger->error('Could not figure out the tenant id. (Is the discovery endpoint formatted properly?) Will not sync groups');
			return null;
		}
		$tenant = $tenant[1];

		$client = $this->clientService->newClient();
		try {
			$response = $client->post("https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", [
				'headers' => [ 'Accept' => 'application/json' ],
				'form_params' => [
					'client_id' => $provider->getClientId(),
					'scope' => 'https://graph.microsoft.com/.default',
					'client_secret' => $this->crypto->decrypt($provider->getClientSecret()),
					'grant_type' => 'client_credentials'
				],
				'http_errors' => false
			]);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage());
			return null;
		}

		$res = $response->getBody();
		if (!is_string($res)) {
			$this->logger->error('Could not fetch Bearer token for Microsoft Graph. Will not sync groups');
			return null;
		}

		$res = json_decode($res, true);
		if (empty($res) || empty($res['access_token']) || !is_string($res['access_token'])) {
			$this->logger->error('Could not fetch Bearer token for Microsoft Graph. Will not sync groups');
			return null;
		}

		return [
			'tenant' => $tenant,
			'token' => $res['access_token'],
		];
	}

	private function getAzureGroupWithResolvedName(int $providerId, string $tenant, string $token, string $groupId): ?object {
		$client = $this->clientService->newClient();
		try {
			$response = $client->get(
				"https://graph.microsoft.com/v1.0/$tenant/groups/" . $groupId,
				[ 'headers' => [ 'Accept' => 'application/json', 'Authorization' => "Bearer $token" ], 'http_errors' => false ]
			);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage());
			return null;
		}

		$res = $response->getBody();
		if (!is_string($res)) {
			$this->logger->error('No response from Microsoft Graph while fetching group name. Will not sync the group ' . $groupId);
			return null;
		}

		$res = json_decode($res, true); // https://learn.microsoft.com/en-us/graph/api/group-get?view=graph-rest-1.0&tabs=http#response-1
		if (isset($res['error'])) {
			$errorMessage = !empty($res['error']['message']) && is_string($res['error']['message']) ? $res['error']['message'] : '';
			$this->logger->error('Error response from Microsoft Graph while fetching group name. Will not sync the group ' . $groupId . '. Graph said: ' . $errorMessage);
			return null;
		}

		if (empty($res['displayName'])) {
			$this->logger->error('Empty response from Microsoft Graph while fetching group name. Will not sync the group ' . $groupId);
			return null;
		}

		$group = (object)['gid' => $res['displayName']];
		if ($this->providerService->getSetting($providerId, ProviderService::SETTING_PROVIDER_BASED_ID, '0') === '1') {
			$providerName = $this->providerMapper->getProvider($providerId)->getIdentifier();
			$group->gid = $providerName . '-' . $group->gid;
		}

		if (strlen($group->gid) > 64) {
			$this->logger->warning('Group id ' . $group->gid . ' longer than supported. Group id truncated.');
			$group->displayName = $group->gid;
			$group->gid = substr($group->gid, 0, 64);
			if (strlen($group->displayName) > 255) {
				$this->logger->warning('Group name ' . $group->displayName . ' longer than supported. Group name truncated.');
				$group->displayName = substr($group->displayName, 0, 255);
			}
		}

		return $group;
	}

	public function provisionUserGroups(IUser $user, int $providerId, object $idTokenPayload): ?array {
		if (!$this->isLoginTimeProvisioningEnabled()) {
			$this->logger->debug('Login-time group provisioning is disabled (JUN-836), skipping');
			return null;
		}

		$groupsWhitelistRegex = $this->getGroupWhitelistRegex($providerId);

		$syncGroups = $this->getSyncGroupsOfToken($providerId, $idTokenPayload);

		if ($syncGroups === null) {
			return null;
		}

		$protectedGroups = $this->getProtectedGroups($providerId);
		$userGroups = $this->groupManager->getUserGroupIds($user);
		foreach ($userGroups as $groupGID) {
			if (!in_array($groupGID, array_column($syncGroups, 'gid'))) {
				// Skip protected groups - never remove users from these groups
				if (in_array($groupGID, $protectedGroups)) {
					$this->logger->debug('Skipped removing user from protected group `' . $groupGID . '`');
					continue;
				}
				if ($groupsWhitelistRegex && !preg_match($groupsWhitelistRegex, $groupGID)) {
					continue;
				}
				$this->groupManager->get($groupGID)?->removeUser($user);
			}
		}

		foreach ($syncGroups as $group) {
			// Creates a new group or return the exiting one.
			if ($newGroup = $this->groupManager->createGroup($group->gid)) {
				// Adds the user to the group. Does nothing if user is already in the group.
				$newGroup->addUser($user);

				if (isset($group->displayName)) {
					$newGroup->setDisplayName($group->displayName);
				}
			}
		}

		return $syncGroups;
	}

	public function getGroupWhitelistRegex(int $providerId): string {
		$regex = $this->providerService->getSetting($providerId, ProviderService::SETTING_GROUP_WHITELIST_REGEX, '');

		// If regex does not start with '/', add '/' to the beginning and end
		// Only check first character to allow for flags at the end of the regex
		if ($regex && substr($regex, 0, 1) !== '/') {
			$regex = '/' . $regex . '/';
		}

		return $regex;
	}

	/**
	 * Get list of protected groups that should never have users removed from them
	 * These are Nextcloud system groups that are not managed by OIDC/Keycloak
	 *
	 * @param int $providerId The provider ID
	 * @return array Array of protected group IDs
	 */
	private function getProtectedGroups(int $providerId): array {
		$protectedGroupsSetting = $this->providerService->getSetting($providerId, ProviderService::SETTING_PROTECTED_GROUPS, 'users,admin');

		if (empty($protectedGroupsSetting)) {
			return ['users', 'admin'];
		}

		// Split comma-separated string and trim whitespace
		$groups = array_map('trim', explode(',', $protectedGroupsSetting));
		// Filter out empty values
		$groups = array_filter($groups, function ($group) {
			return !empty($group);
		});

		// Return default if no valid groups found
		if (empty($groups)) {
			return ['users', 'admin'];
		}

		return array_values($groups);
	}

	/**
	 * Get the Teams whitelist regex for filtering organization-based circles
	 */
	public function getTeamsWhitelistRegex(int $providerId): string {
		$regex = $this->providerService->getSetting($providerId, ProviderService::SETTING_TEAMS_WHITELIST_REGEX, '');

		// If regex does not start with '/', add '/' to the beginning and end
		if ($regex && substr($regex, 0, 1) !== '/') {
			$regex = '/' . $regex . '/';
		}

		return $regex;
	}

	/**
	 * Get organizations from the ID token payload
	 * Supports both Keycloak Organizations format and simple array format
	 *
	 * Keycloak Organizations format:
	 * {
	 *   "organizations": {
	 *     "org-id-1": { "name": "Engineering", "roles": ["member", "admin"] },
	 *     "org-id-2": { "name": "Marketing", "roles": ["member"] }
	 *   }
	 * }
	 *
	 * Simple array format:
	 * { "organizations": ["org1", "org2"] }
	 *
	 * @param int $providerId The provider ID
	 * @param object $idTokenPayload The ID token payload
	 * @return array|null Array of organization objects with 'id' and 'name', or null if not available
	 */
	public function getOrganizationsFromToken(int $providerId, object $idTokenPayload): ?array {
		$orgAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_ORGANIZATIONS, 'organizations');
		$orgData = $this->getClaimValues($idTokenPayload, $orgAttribute, $providerId);

		$teamsWhitelistRegex = $this->getTeamsWhitelistRegex($providerId);

		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_ORGANIZATIONS, $idTokenPayload, json_encode($orgData));
		$this->eventDispatcher->dispatchTyped($event);
		$this->logger->debug('Organization mapping event dispatched');

		if (!$event->hasValue() || $event->getValue() === null) {
			return null;
		}

		$orgsRaw = json_decode($event->getValue() ?? '', true);
		if ($orgsRaw === null) {
			return null;
		}

		$organizations = [];

		// Handle Keycloak Organizations format (object with org IDs as keys)
		if (is_array($orgsRaw) && !array_is_list($orgsRaw)) {
			foreach ($orgsRaw as $orgId => $orgData) {
				$orgName = is_array($orgData) && isset($orgData['name']) ? $orgData['name'] : $orgId;
				$org = (object)[
					'id' => $orgId,
					'name' => $orgName,
					'roles' => is_array($orgData) && isset($orgData['roles']) ? $orgData['roles'] : [],
				];

				// Apply whitelist regex
				if ($teamsWhitelistRegex) {
					$matchResult = preg_match($teamsWhitelistRegex, $org->name);
					if ($matchResult !== 1) {
						$this->logger->debug('Skipped organization `' . $org->name . '` for Teams provisioning (not matching whitelist regex)');
						continue;
					}
				}

				$organizations[] = $org;
			}
		} else {
			// Handle simple array format (list of strings)
			foreach ($orgsRaw as $orgName) {
				if (!is_string($orgName)) {
					continue;
				}

				$org = (object)[
					'id' => $orgName,
					'name' => $orgName,
					'roles' => [],
				];

				// Apply whitelist regex
				if ($teamsWhitelistRegex) {
					$matchResult = preg_match($teamsWhitelistRegex, $org->name);
					if ($matchResult !== 1) {
						$this->logger->debug('Skipped organization `' . $org->name . '` for Teams provisioning (not matching whitelist regex)');
						continue;
					}
				}

				$organizations[] = $org;
			}
		}

		return count($organizations) > 0 ? $organizations : null;
	}

	/**
	 * Provision user's Teams/Circles based on their organization memberships
	 * Creates circles if they don't exist, adds user to circles they should belong to,
	 * and removes them from circles they no longer belong to (within whitelist scope)
	 *
	 * @param IUser $user The user to provision teams for
	 * @param int $providerId The provider ID
	 * @param object $idTokenPayload The ID token payload containing organization claims
	 * @return array|null Array of provisioned organizations, or null if Teams provisioning is disabled
	 */
	public function provisionUserTeams(IUser $user, int $providerId, object $idTokenPayload): ?array {
		if (!$this->isLoginTimeProvisioningEnabled()) {
			$this->logger->debug('Login-time team/circle provisioning is disabled (JUN-836), skipping');
			return null;
		}

		// Check if CirclesService is available
		if ($this->circlesService === null) {
			$this->logger->debug('CirclesService not available, skipping Teams provisioning');
			return null;
		}

		// Check if Circles app is enabled
		if (!$this->circlesService->isCirclesEnabled()) {
			$this->logger->debug('Circles app is not enabled, skipping Teams provisioning');
			return null;
		}

		$teamsWhitelistRegex = $this->getTeamsWhitelistRegex($providerId);
		$syncOrganizations = $this->getOrganizationsFromToken($providerId, $idTokenPayload);

		if ($syncOrganizations === null) {
			$this->logger->debug('No organizations found in token for user ' . $user->getUID());
			return null;
		}

		$this->logger->info('Provisioning Teams for user ' . $user->getUID() . ' with ' . count($syncOrganizations) . ' organizations');

		// Get user's current circles for cleanup
		$currentCircles = $this->circlesService->getUserCircles($user);
		$currentCircleNames = [];
		foreach ($currentCircles as $circle) {
			$currentCircleNames[] = $circle->getDisplayName();
		}

		// Track which circles the user should be in
		$targetCircleNames = array_map(function ($org) {
			return $org->name;
		}, $syncOrganizations);

		// Remove user from circles they no longer belong to (within whitelist scope)
		foreach ($currentCircles as $circle) {
			$circleName = $circle->getDisplayName();

			// Skip if circle is in target list
			if (in_array($circleName, $targetCircleNames)) {
				continue;
			}

			// Skip if circle doesn't match whitelist (we only manage whitelisted circles)
			if ($teamsWhitelistRegex && !preg_match($teamsWhitelistRegex, $circleName)) {
				continue;
			}

			// Remove user from circle
			$this->circlesService->removeMember($circle, $user);
		}

		// Add user to circles they should belong to
		foreach ($syncOrganizations as $org) {
			// Create or get the circle
			$circle = $this->circlesService->getOrCreateCircle($org->id, $org->name);
			if ($circle === null) {
				$this->logger->warning('Failed to get or create circle for organization: ' . $org->name);
				continue;
			}

			// Add user to circle
			$this->circlesService->addMember($circle, $user);
		}

		return $syncOrganizations;
	}
}
