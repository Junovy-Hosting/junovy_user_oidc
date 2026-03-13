<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Tests\Unit\Service;

use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Service\CirclesService;
use OCA\UserOIDC\Service\GroupFoldersService;
use OCA\UserOIDC\Service\KeycloakAdminService;
use OCA\UserOIDC\Service\LocalIdService;
use OCA\UserOIDC\Service\ReconciliationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconciliationServiceTest extends TestCase {

	private ReconciliationService $service;
	private KeycloakAdminService $keycloakAdmin;
	private CirclesService $circlesService;
	private GroupFoldersService $groupFoldersService;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private UserMapper $userMapper;
	private LocalIdService $localIdService;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->keycloakAdmin = $this->createMock(KeycloakAdminService::class);
		$this->circlesService = $this->createMock(CirclesService::class);
		$this->groupFoldersService = $this->createMock(GroupFoldersService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userMapper = $this->createMock(UserMapper::class);
		$this->localIdService = $this->createMock(LocalIdService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new ReconciliationService(
			$this->keycloakAdmin,
			$this->circlesService,
			$this->groupFoldersService,
			$this->groupManager,
			$this->userManager,
			$this->userMapper,
			$this->localIdService,
			$this->logger,
		);
	}

	public function testDryRunReportsPlannedActions(): void {
		// Keycloak has one org with one member
		$this->keycloakAdmin->method('getOrganizations')->willReturn([
			[
				'id' => 'org-1',
				'name' => 'Kindred Wellness',
				'alias' => 'kindred-wellness',
				'attributes' => ['nextcloud:folder_quota' => ['250 GB']],
			],
		]);
		$this->keycloakAdmin->method('getOrganizationMembers')->willReturn([
			['id' => 'kc-user-1', 'username' => 'william-drake'],
		]);
		$this->keycloakAdmin->method('getGroups')->willReturn([]);

		// No circles exist yet
		$this->circlesService->method('isCirclesEnabled')->willReturn(true);
		$this->circlesService->method('getAllCircles')->willReturn([]);

		// getOrCreateCircle returns null (circle doesn't exist, dry-run won't create)
		$this->circlesService->method('getOrCreateCircle')->willReturn(null);

		// No group folders exist yet
		$this->groupFoldersService->method('isGroupFoldersEnabled')->willReturn(true);
		$this->groupFoldersService->method('listFolders')->willReturn([]);

		// LocalIdService computes the Nextcloud user_id from Keycloak sub
		$computedUserId = hash('sha256', '1_0_kc-user-1');
		$this->localIdService->method('getId')->willReturn($computedUserId);

		// User exists in Nextcloud
		$this->userMapper->method('userExists')->with($computedUserId)->willReturn(true);
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn($computedUserId);
		$this->userManager->method('get')->with($computedUserId)->willReturn($mockUser);

		// groupManager->search('') is called during cleanup — return empty array
		$this->groupManager->method('search')->willReturn([]);

		$result = $this->service->reconcile(providerId: 1, dryRun: true);

		$this->assertTrue($result['dry_run']);
		$this->assertGreaterThan(0, count($result['actions']));
		// Should plan to create a circle
		$actionTypes = array_column($result['actions'], 'type');
		$this->assertContains('create_circle', $actionTypes);
	}
}
