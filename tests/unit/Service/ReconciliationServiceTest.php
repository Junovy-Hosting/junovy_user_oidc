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
use OCP\IGroup;
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

		// GroupFolders enabled but empty listing triggers safety guard
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
		// Should warn about empty folder listing
		$this->assertNotEmpty($result['warnings']);
	}

	public function testGroupMemberRemovalSkippedWhenUsersUnresolved(): void {
		// Keycloak has no orgs but has one group with 2 members
		$this->keycloakAdmin->method('getOrganizations')->willReturn([]);
		$this->keycloakAdmin->method('getGroups')->willReturn([
			['id' => 'grp-1', 'name' => 'junovy-talk', 'attributes' => []],
		]);
		$this->keycloakAdmin->method('getGroupMembers')->willReturn([
			['id' => 'kc-user-1', 'username' => 'user1'],
			['id' => 'kc-user-2', 'username' => 'user2'],
		]);

		// Circles disabled (skip org sync)
		$this->circlesService->method('isCirclesEnabled')->willReturn(false);

		// GroupFolders disabled
		$this->groupFoldersService->method('isGroupFoldersEnabled')->willReturn(false);

		// The group exists in Nextcloud with 3 members (user1-nc, user2-nc, user3-nc)
		$this->groupManager->method('groupExists')->willReturn(true);

		$existingUser1 = $this->createMock(IUser::class);
		$existingUser1->method('getUID')->willReturn('user1-nc');
		$existingUser2 = $this->createMock(IUser::class);
		$existingUser2->method('getUID')->willReturn('user2-nc');
		$existingUser3 = $this->createMock(IUser::class);
		$existingUser3->method('getUID')->willReturn('user3-nc');

		$mockGroup = $this->createMock(IGroup::class);
		$mockGroup->method('getUsers')->willReturn([$existingUser1, $existingUser2, $existingUser3]);
		$this->groupManager->method('get')->with('junovy-talk')->willReturn($mockGroup);

		// Only user1 can be resolved, user2 cannot (not yet provisioned)
		$computedUserId1 = hash('sha256', '1_0_kc-user-1');
		$this->localIdService->method('getId')->willReturnMap([
			[1, 'kc-user-1', $computedUserId1],
			[1, 'kc-user-2', hash('sha256', '1_0_kc-user-2')],
		]);
		$this->userMapper->method('userExists')->willReturnMap([
			[$computedUserId1, true],
			[hash('sha256', '1_0_kc-user-2'), false], // user2 not provisioned
		]);

		$resolvedUser = $this->createMock(IUser::class);
		$resolvedUser->method('getUID')->willReturn($computedUserId1);
		$this->userManager->method('get')->willReturnMap([
			[$computedUserId1, $resolvedUser],
		]);

		// Cleanup search
		$this->groupManager->method('search')->willReturn([]);

		$result = $this->service->reconcile(providerId: 1, dryRun: false);

		// Should NOT have removed any members — 1 of 2 Keycloak members was unresolved
		$actionTypes = array_column($result['actions'], 'type');
		$this->assertNotContains('remove_group_member', $actionTypes);
		// Should have a warning about unresolved members
		$warningText = implode(' ', $result['warnings']);
		$this->assertStringContainsString('skipping member removal', $warningText);
	}
}
