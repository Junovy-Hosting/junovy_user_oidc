<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Tests\Unit\Service;

use OCA\UserOIDC\Service\KeycloakAdminService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KeycloakAdminServiceTest extends TestCase {

	private KeycloakAdminService $service;
	private IClient $httpClient;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->httpClient = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->httpClient);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new KeycloakAdminService(
			$clientService,
			$this->logger,
			'http://keycloak.local/realms/dds',
			'test-client-id',
			'test-client-secret',
		);
	}

	public function testFetchAccessToken(): void {
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access_token' => 'test-token-123',
			'expires_in' => 300,
			'token_type' => 'Bearer',
		]));
		$tokenResponse->method('getStatusCode')->willReturn(200);

		$this->httpClient->expects($this->once())
			->method('post')
			->with(
				'http://keycloak.local/realms/dds/protocol/openid-connect/token',
				$this->callback(function ($options) {
					return $options['body']['grant_type'] === 'client_credentials'
						&& $options['body']['client_id'] === 'test-client-id'
						&& $options['body']['client_secret'] === 'test-client-secret';
				})
			)
			->willReturn($tokenResponse);

		$token = $this->service->getAccessToken();

		$this->assertEquals('test-token-123', $token);
	}
}
