<?php

/**
 * SPDX-FileCopyrightText: 2026 Junovy Hosting
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Builds the user-facing pages shown when an OpenID Connect login cannot be completed.
 *
 * Every failure is mapped to one of three pages (JUN-2078):
 *  - KIND_EXPIRED: the login flow can simply be started again (state/nonce/token expired or mismatched,
 *    the user cancelled at the IdP, ...). The page auto-continues to a fresh login after a short countdown.
 *  - KIND_NOT_SHARED: the account authenticated but is not allowed in (login restricted to groups).
 *  - KIND_TROUBLE: something is wrong on our side (provider misconfiguration, provisioning failure, ...).
 *
 * Technical detail never reaches the headline or body; a short reference is rendered in the footer
 * and logged together with the reason so support can correlate the two.
 */
class ErrorPageService {

	public const KIND_EXPIRED = 'expired';
	public const KIND_NOT_SHARED = 'not_shared';
	public const KIND_TROUBLE = 'trouble';

	public const TEMPLATE = 'friendly-error';

	/** Seconds the expired page waits before continuing to a fresh login */
	public const AUTO_CONTINUE_SECONDS = 5;

	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $kind one of the KIND_* constants
	 * @param string $reason short technical reason, logged (never shown)
	 * @param int $statusCode HTTP status of the page
	 * @param array $throttleMetadata brute-force protection metadata
	 * @param bool|null $throttle null = throttle unless debug mode is on
	 * @param int|null $providerId provider to restart the login with, if known
	 * @param string|null $redirectUrl where the user wanted to go, if known
	 */
	public function build(
		string $kind,
		string $reason,
		int $statusCode,
		array $throttleMetadata = [],
		?bool $throttle = null,
		?int $providerId = null,
		?string $redirectUrl = null,
	): TemplateResponse {
		$ref = $this->random->generate(6, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$this->logger->warning('OIDC sign-in could not be completed: ' . $reason, array_merge($throttleMetadata, [
			'ref' => $ref,
			'kind' => $kind,
			'provider_id' => $providerId,
		]));

		$params = array_merge($this->copyFor($kind, $providerId, $redirectUrl), [
			'kind' => $kind,
			'ref' => $ref,
			'timestamp' => (string)$this->l->l('datetime', $this->timeFactory->getDateTime(), ['width' => 'medium']),
			'supportUrl' => $this->getSupportUrl(),
			'supportLabel' => $this->l->t('Still stuck?'),
			'supportLinkLabel' => $this->l->t('Contact support'),
			'continueUrl' => $this->getContinueUrl($providerId, $redirectUrl),
			'autoContinueSeconds' => self::AUTO_CONTINUE_SECONDS,
			'stayLabel' => $this->l->t('Stay here'),
			'countdownLabel' => $this->l->n(
				'Continuing in %n second', 'Continuing in %n seconds', self::AUTO_CONTINUE_SECONDS
			),
		]);

		$response = new TemplateResponse(Application::APP_ID, self::TEMPLATE, $params, TemplateResponse::RENDER_AS_ERROR);
		$response->setStatus($statusCode);
		$debug = $this->config->getSystemValueBool('debug', false);
		if (($throttle === null && !$debug) || $throttle) {
			$response->throttle($throttleMetadata);
		}
		return $response;
	}

	/**
	 * @return array{title: string, body: string, primary: array{label: string, href: string}, secondary: ?array{label: string, href: string}, autoContinue: bool}
	 */
	private function copyFor(string $kind, ?int $providerId, ?string $redirectUrl): array {
		$continueUrl = $this->getContinueUrl($providerId, $redirectUrl);
		switch ($kind) {
			case self::KIND_NOT_SHARED:
				return [
					'title' => $this->l->t('This hasn\'t been shared with you yet'),
					'body' => $this->l->t('Ask whoever sent you the link to share it with you, then try again.'),
					'primary' => ['label' => $this->l->t('Go to my files'), 'href' => $this->getFilesUrl()],
					'secondary' => ['label' => $this->l->t('Try again'), 'href' => $continueUrl],
					'autoContinue' => false,
				];
			case self::KIND_TROUBLE:
				return [
					'title' => $this->l->t('Something went wrong on our side'),
					'body' => $this->l->t('We couldn\'t finish signing you in. Try again in a moment, and let us know if it keeps happening.'),
					'primary' => ['label' => $this->l->t('Try again'), 'href' => $continueUrl],
					'secondary' => ['label' => $this->l->t('Go to the home page'), 'href' => $this->urlGenerator->getBaseUrl()],
					'autoContinue' => false,
				];
			case self::KIND_EXPIRED:
			default:
				return [
					'title' => $this->l->t('Welcome back'),
					'body' => $this->l->t('The sign-in page sat open too long, so we\'ll start it fresh. Your account and files are safe.'),
					'primary' => ['label' => $this->l->t('Continue to sign in'), 'href' => $continueUrl],
					'secondary' => null,
					'autoContinue' => true,
				];
		}
	}

	/**
	 * A fresh login with the same provider (and destination) when we know it, the home page otherwise.
	 * The home page redirects to the login flow anyway when nobody is signed in.
	 */
	private function getContinueUrl(?int $providerId, ?string $redirectUrl): string {
		if ($providerId === null || $providerId <= 0) {
			return $this->urlGenerator->getBaseUrl();
		}
		$args = ['providerId' => $providerId];
		if ($redirectUrl !== null && $redirectUrl !== '') {
			$args['redirectUrl'] = $redirectUrl;
		}
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.login.login', $args);
	}

	private function getFilesUrl(): string {
		if ($this->userSession->isLoggedIn()) {
			return $this->urlGenerator->linkToRoute('files.view.index');
		}
		return $this->urlGenerator->getBaseUrl();
	}

	/**
	 * Configured as `'junovy_user_oidc' => ['support_url' => '...']` in config.php; the support line is hidden when unset.
	 */
	private function getSupportUrl(): ?string {
		$oidcSystemConfig = $this->config->getSystemValue(Application::APP_ID, []);
		$url = is_array($oidcSystemConfig) ? trim((string)($oidcSystemConfig['support_url'] ?? '')) : '';
		return $url === '' ? null : $url;
	}
}
