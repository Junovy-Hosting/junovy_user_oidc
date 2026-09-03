<?php

/**
 * SPDX-FileCopyrightText: 2026 Junovy Hosting
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Service\ErrorPageService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ErrorPageServiceTest extends TestCase {
	private ErrorPageService $service;
	/** @var IL10N|MockObject */
	private $l;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var IUserSession|MockObject */
	private $userSession;
	/** @var ISecureRandom|MockObject */
	private $random;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var IConfig|MockObject */
	private $config;
	/** @var LoggerInterface|MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(fn (string $text, $params = []) => vsprintf($text, (array)$params));
		$this->l->method('n')->willReturnCallback(
			fn (string $singular, string $plural, int $count) => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);
		$this->l->method('l')->willReturn('3 Sep 2026, 13:09');
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getBaseUrl')->willReturn('https://cloud.example.org');
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			fn (string $route, array $args = []) => '/route/' . $route . ($args === [] ? '' : '?' . http_build_query($args))
		);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturn('7f3a2c');
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-03 13:09:00'));
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueBool')->with('debug', false)->willReturn(false);
		$this->config->method('getSystemValue')->with('junovy_user_oidc', [])->willReturn(['support_url' => 'https://junovy.com/support']);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ErrorPageService(
			$this->l,
			$this->urlGenerator,
			$this->userSession,
			$this->random,
			$this->timeFactory,
			$this->config,
			$this->logger,
		);
	}

	public function testExpiredPageAutoContinuesToAFreshLogin(): void {
		$response = $this->service->build(
			ErrorPageService::KIND_EXPIRED, 'state expired', Http::STATUS_FORBIDDEN, [], false, 3, '/apps/files/?dir=/Shared'
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(ErrorPageService::TEMPLATE, $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_ERROR, $response->getRenderAs());
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->isThrottled());

		$params = $response->getParams();
		$this->assertSame('expired', $params['kind']);
		$this->assertSame('Welcome back', $params['title']);
		$this->assertTrue($params['autoContinue']);
		$this->assertNull($params['secondary']);
		$expectedContinue = '/route/' . Application::APP_ID . '.login.login?providerId=3&redirectUrl=' . urlencode('/apps/files/?dir=/Shared');
		$this->assertSame($expectedContinue, $params['continueUrl']);
		$this->assertSame($expectedContinue, $params['primary']['href']);
		$this->assertSame('Continue to sign in', $params['primary']['label']);
		$this->assertSame('Continuing in 5 seconds', $params['countdownLabel']);
		$this->assertSame('7f3a2c', $params['ref']);
		$this->assertSame('3 Sep 2026, 13:09', $params['timestamp']);
		$this->assertSame('https://junovy.com/support', $params['supportUrl']);
	}

	public function testNotSharedPageSendsSignedInUsersToFiles(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);

		$params = $this->service->build(ErrorPageService::KIND_NOT_SHARED, 'user not in any whitelisted group', Http::STATUS_FORBIDDEN, [], false, 3)->getParams();

		$this->assertSame('This hasn\'t been shared with you yet', $params['title']);
		$this->assertFalse($params['autoContinue']);
		$this->assertSame('Go to my files', $params['primary']['label']);
		$this->assertSame('/route/files.view.index', $params['primary']['href']);
		$this->assertSame('Try again', $params['secondary']['label']);
		$this->assertSame('/route/' . Application::APP_ID . '.login.login?providerId=3', $params['secondary']['href']);
	}

	public function testNotSharedPageSendsGuestsToTheHomePage(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);

		$params = $this->service->build(ErrorPageService::KIND_NOT_SHARED, 'user not in any whitelisted group', Http::STATUS_FORBIDDEN, [], false, 3)->getParams();

		$this->assertSame('https://cloud.example.org', $params['primary']['href']);
	}

	public function testTroublePageWithoutAProviderRetriesFromTheHomePage(): void {
		$params = $this->service->build(ErrorPageService::KIND_TROUBLE, 'provider not found', Http::STATUS_NOT_FOUND)->getParams();

		$this->assertSame('Something went wrong on our side', $params['title']);
		$this->assertFalse($params['autoContinue']);
		$this->assertSame('Try again', $params['primary']['label']);
		$this->assertSame('https://cloud.example.org', $params['primary']['href']);
		$this->assertSame('https://cloud.example.org', $params['secondary']['href']);
	}

	public function testThrottlesByDefaultWhenDebugModeIsOff(): void {
		$response = $this->service->build(ErrorPageService::KIND_EXPIRED, 'state does not match', Http::STATUS_FORBIDDEN, ['reason' => 'state does not match'], null, 3);

		$this->assertTrue($response->isThrottled());
		$this->assertSame(['reason' => 'state does not match'], $response->getThrottleMetadata());
	}

	public function testLogsTheReferenceWithTheTechnicalReason(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('state expired'),
				$this->callback(fn (array $context) => $context['ref'] === '7f3a2c' && $context['kind'] === 'expired' && $context['provider_id'] === 3)
			);

		$this->service->build(ErrorPageService::KIND_EXPIRED, 'state expired', Http::STATUS_FORBIDDEN, [], false, 3);
	}

	public function testSupportLineIsHiddenWhenNoSupportUrlIsConfigured(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getSystemValue')->willReturn([]);
		$service = new ErrorPageService($this->l, $this->urlGenerator, $this->userSession, $this->random, $this->timeFactory, $config, $this->logger);

		$params = $service->build(ErrorPageService::KIND_EXPIRED, 'state expired', Http::STATUS_FORBIDDEN, [], false, 3)->getParams();

		$this->assertNull($params['supportUrl']);
	}
}
