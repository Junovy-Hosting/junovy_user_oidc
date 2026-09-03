<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ProviderService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version080101Date20251201121749 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
		private ProviderMapper $providerMapper,
		private ProviderService $providerService,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// make admin settings lazy
		$keys = [
			'store_login_token',
			'id4me_enabled',
			'allow_multiple_user_backends',
		];
		foreach ($keys as $key) {
			try {
				if ($this->appConfig->hasKey(Application::APP_ID, $key)) {
					$value = $this->appConfig->getValueString(Application::APP_ID, $key);
					$this->appConfig->setValueString(Application::APP_ID, $key, $value, lazy: true);
				}
			} catch (\Exception) {
			}
		}

		// make all provider settings lazy
		$providers = $this->providerMapper->getProviders();
		// Junovy: the fork adds a lot of per-provider settings (TLS verify, URL overrides,
		// cache times, ...) on top of upstream's list, so take the live list instead of a
		// copy; reading a non-lazy value with lazy: true returns the default, and every
		// setting that is not converted here would silently fall back to its default.
		$supportedSettingKeys = $this->providerService->getSupportedSettings();
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE;
		$supportedSettingKeys[] = ProviderService::SETTING_JWKS_CACHE_TIMESTAMP;
		foreach ($supportedSettingKeys as $key) {
			foreach ($providers as $provider) {
				// equivalent of $this->providerService->getSettingsKey($provider->getId(), $key)
				$realKey = 'provider-' . strval($provider->getId()) . '-' . $key;
				if ($this->appConfig->hasKey(Application::APP_ID, $realKey)) {
					try {
						$value = $this->appConfig->getValueString(Application::APP_ID, $realKey);
						$this->appConfig->setValueString(Application::APP_ID, $realKey, $value, lazy: true);
					} catch (\Exception) {
					}
				}
			}
		}
	}
}
