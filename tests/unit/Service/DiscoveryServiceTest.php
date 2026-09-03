<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Helper\HttpClientHelper;
use OCA\UserOIDC\Service\DiscoveryService;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DiscoveryServiceTest extends TestCase {

	/** @var MockObject|LoggerInterface */
	private $logger;
	/** @var HttpClientHelper|MockObject */
	private $clientHelper;
	/** @var ProviderService|MockObject */
	private $providerService;
	/** @var IConfig|MockObject */
	private $config;
	/** @var ICacheFactory|MockObject */
	private $cacheFactory;
	/** @var ICache|MockObject */
	private $cache;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var DiscoveryService */
	private $discoveryService;

	public function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->clientHelper = $this->createMock(HttpClientHelper::class);
		$this->providerService = $this->createMock(ProviderService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::once())
			->method('createDistributed')
			->with('junovy_user_oidc')
			->willReturn($this->cache);
		$this->discoveryService = new DiscoveryService($this->logger, $this->clientHelper, $this->providerService, $this->config, $this->timeFactory, $this->cacheFactory);
	}

	/**
	 * Test that fixJwksAlg filters out keys with unsupported key types.
	 * This prevents Firebase JWT from crashing on P-521 or OKP keys.
	 * See https://github.com/firebase/php-jwt/issues/561
	 */
	public function testFixJwksAlgFiltersUnsupportedKeyTypes() {
		// Build a fake JWT with RS256 alg and a known kid
		$header = json_encode(['alg' => 'RS256', 'kid' => 'rsa-key-1', 'typ' => 'JWT']);
		$payload = json_encode(['sub' => '1234']);
		$fakeJwt = rtrim(strtr(base64_encode($header), '+/', '-_'), '=')
			. '.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
			. '.fake-signature';

		// JWKS with mixed key types: RSA (matching), EC P-521, and OKP
		$jwks = [
			'keys' => [
				[
					'kty' => 'EC',
					'crv' => 'P-521',
					'kid' => 'ec-p521-key',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
				[
					'kty' => 'RSA',
					'kid' => 'rsa-key-1',
					'use' => 'sig',
					'n' => str_repeat('A', 342), // Fake 2048-bit modulus (256 bytes base64)
					'e' => 'AQAB',
				],
				[
					'kty' => 'OKP',
					'crv' => 'Ed25519',
					'kid' => 'okp-key',
					'use' => 'sig',
					'x' => 'some-value',
				],
			],
		];

		// Mock config to disable key strength validation (we use fake key material)
		$this->config->method('getSystemValue')
			->with('junovy_user_oidc', [])
			->willReturn(['validate_jwk_strength' => false]);

		$result = $this->discoveryService->fixJwksAlg($jwks, $fakeJwt);

		// Only the RSA key should remain
		Assert::assertCount(1, $result['keys']);
		Assert::assertEquals('RSA', $result['keys'][0]['kty']);
		Assert::assertEquals('rsa-key-1', $result['keys'][0]['kid']);
		Assert::assertEquals('RS256', $result['keys'][0]['alg']);
	}

	/**
	 * Test that fixJwksAlg works with EC keys when JWT uses ES256.
	 */
	public function testFixJwksAlgKeepsCompatibleEcKeys() {
		$header = json_encode(['alg' => 'ES256', 'kid' => 'ec-key-1', 'typ' => 'JWT']);
		$payload = json_encode(['sub' => '1234']);
		$fakeJwt = rtrim(strtr(base64_encode($header), '+/', '-_'), '=')
			. '.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
			. '.fake-signature';

		$jwks = [
			'keys' => [
				[
					'kty' => 'RSA',
					'kid' => 'rsa-key-1',
					'use' => 'sig',
					'n' => str_repeat('A', 342),
					'e' => 'AQAB',
				],
				[
					'kty' => 'EC',
					'crv' => 'P-256',
					'kid' => 'ec-key-1',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
				[
					'kty' => 'EC',
					'crv' => 'P-521',
					'kid' => 'ec-p521-key',
					'use' => 'sig',
					'x' => 'AekpBQ8ST8a8VcfVOTNl353vSrDCLL-Jmn1TZOFz5EhU',
					'y' => 'ADSmRA43Z1DSNx_RvcLI87cdL07l6jQyyBXMoxVg_l2T',
				],
			],
		];

		$this->config->method('getSystemValue')
			->with('junovy_user_oidc', [])
			->willReturn(['validate_jwk_strength' => false]);

		$result = $this->discoveryService->fixJwksAlg($jwks, $fakeJwt);

		// Both EC keys should remain (same kty), RSA filtered out
		Assert::assertCount(2, $result['keys']);
		Assert::assertEquals('EC', $result['keys'][0]['kty']);
		Assert::assertEquals('ec-key-1', $result['keys'][0]['kid']);
		Assert::assertEquals('EC', $result['keys'][1]['kty']);
	}

	public function testBuildAuthorizationUrl() {
		$xss1 = '\'"http-equiv=><svg/onload=alert(1)>';
		$cleanedXss1 = '&#039;&quot;http-equiv=&gt;&lt;svg/onload=alert(1)&gt;';
		$cleanAuthorizationEndpoint = 'https://test.org:9999/path1/path2';
		$stringQueryParams = 'param1=value1&param2=value2';
		$extraParams = [
			'extraParam1' => 'extraValue1',
			'extraParam2' => 'extraValue2',
		];
		$stringExtraParams = 'extraParam1=extraValue1&extraParam2=extraValue2';

		$extraParamsWithXssValue = [
			'extraParam1' => $xss1,
		];
		$extraParamsWithXssKey = [
			$xss1 => 'extraValue1',
		];

		$testValues = [
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . $xss1,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint . $cleanedXss1,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => [],
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParams,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringExtraParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParams,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . $stringExtraParams . '&' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParamsWithXssKey,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . urlencode($xss1) . '=extraValue1',
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint,
				'extra_params' => $extraParamsWithXssValue,
				'expected_result' => $cleanAuthorizationEndpoint . '?extraParam1=' . urlencode($xss1),
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParamsWithXssKey,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . urlencode($xss1) . '=extraValue1' . '&' . $stringQueryParams,
			],
			[
				'authorization_endpoint' => $cleanAuthorizationEndpoint . '?' . $stringQueryParams,
				'extra_params' => $extraParamsWithXssValue,
				'expected_result' => $cleanAuthorizationEndpoint . '?' . 'extraParam1=' . urlencode($xss1) . '&' . $stringQueryParams,
			],
		];

		foreach ($testValues as $test) {
			Assert::assertEquals(
				$test['expected_result'],
				$this->discoveryService->buildAuthorizationUrl($test['authorization_endpoint'], $test['extra_params'])
			);
		}
	}

	public function testObtainDiscoveryWithUrlOverrides() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://external.example.com/token',
			'jwks_uri' => 'https://external.example.com/jwks',
			'userinfo_endpoint' => 'https://external.example.com/userinfo',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->with('https://example.com/.well-known/openid-configuration', [], ['verify' => true])
			->willReturn($discoveryResponse);

		// Mock cache set
		$this->cache->expects(self::once())
			->method('set')
			->willReturn(true);

		// Mock provider service to return cache time and TLS verify
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 3600],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		// Mock provider service to return URL overrides
		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturnMap([
				[1, ProviderService::SETTING_OVERRIDE_JWKS_URI, '', 'http://internal.example.com/jwks'],
				[1, ProviderService::SETTING_OVERRIDE_TOKEN_ENDPOINT, '', 'http://internal.example.com/token'],
				[1, ProviderService::SETTING_OVERRIDE_USERINFO_ENDPOINT, '', 'http://internal.example.com/userinfo'],
			]);

		$result = $this->discoveryService->obtainDiscovery($provider);

		// Verify that overrides were applied
		Assert::assertEquals('http://internal.example.com/jwks', $result['jwks_uri']);
		Assert::assertEquals('http://internal.example.com/token', $result['token_endpoint']);
		Assert::assertEquals('http://internal.example.com/userinfo', $result['userinfo_endpoint']);
		// Verify original values are preserved
		Assert::assertEquals('https://example.com', $result['issuer']);
		Assert::assertEquals('https://example.com/auth', $result['authorization_endpoint']);
	}

	public function testObtainDiscoveryWithoutUrlOverrides() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://example.com/token',
			'jwks_uri' => 'https://example.com/jwks',
			'userinfo_endpoint' => 'https://example.com/userinfo',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->with('https://example.com/.well-known/openid-configuration', [], ['verify' => true])
			->willReturn($discoveryResponse);

		// Mock cache set
		$this->cache->expects(self::once())
			->method('set')
			->willReturn(true);

		// Mock provider service to return cache time and TLS verify, no overrides
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 3600],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturn('');

		$result = $this->discoveryService->obtainDiscovery($provider);

		// Verify that original values are preserved
		Assert::assertEquals('https://example.com/jwks', $result['jwks_uri']);
		Assert::assertEquals('https://example.com/token', $result['token_endpoint']);
		Assert::assertEquals('https://example.com/userinfo', $result['userinfo_endpoint']);
	}

	public function testObtainDiscoveryWithCacheTimeZero() {
		$provider = new Provider();
		$provider->setId(1);
		$provider->setDiscoveryEndpoint('https://example.com/.well-known/openid-configuration');

		$discoveryResponse = json_encode([
			'issuer' => 'https://example.com',
			'authorization_endpoint' => 'https://example.com/auth',
			'token_endpoint' => 'https://example.com/token',
			'jwks_uri' => 'https://example.com/jwks',
		]);

		// Mock cache to return null (cache miss)
		$this->cache->expects(self::once())
			->method('get')
			->willReturn(null);

		// Mock HTTP client to return discovery response
		$this->clientHelper->expects(self::once())
			->method('get')
			->willReturn($discoveryResponse);

		// Mock provider service to return cache time of 0 (disable caching) and TLS verify
		$this->providerService->expects(self::exactly(2))
			->method('getConfigValue')
			->willReturnMap([
				[1, ProviderService::SETTING_WELL_KNOWN_CACHING_TIME, 3600, 0],
				[1, ProviderService::SETTING_TLS_VERIFY, true, true],
			]);

		$this->providerService->expects(self::exactly(3))
			->method('getSetting')
			->willReturn('');

		// Cache should not be set when cache time is 0
		$this->cache->expects(self::never())
			->method('set');

		$result = $this->discoveryService->obtainDiscovery($provider);
		Assert::assertIsArray($result);
	}
}
