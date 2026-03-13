<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Service;

use OCA\UserOIDC\Db\UserMapper;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

class ReconciliationService {

	private const PROTECTED_GROUPS = ['admin', 'users'];

	/** @var int OIDC provider ID, set per reconciliation run */
	private int $providerId = 0;

	public function __construct(
		private KeycloakAdminService $keycloakAdmin,
		private CirclesService $circlesService,
		private GroupFoldersService $groupFoldersService,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private UserMapper $userMapper,
		private LocalIdService $localIdService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run the full reconciliation.
	 *
	 * @param int $providerId The OIDC provider ID (needed for user identity mapping)
	 * @param bool $dryRun If true, collect planned actions without executing
	 * @param bool $repairOnly If true, only fix corrupted/orphaned resources
	 * @return array Summary with 'actions', 'warnings', 'errors', 'dry_run' keys
	 */
	public function reconcile(int $providerId, bool $dryRun = false, bool $repairOnly = false): array {
		$this->providerId = $providerId;

		$result = [
			'dry_run' => $dryRun,
			'actions' => [],
			'warnings' => [],
			'errors' => [],
		];

		try {
			// Step 1: Fetch desired state from Keycloak
			$kcOrgs = $this->keycloakAdmin->getOrganizations();
			$kcGroups = $repairOnly ? [] : $this->keycloakAdmin->getGroups();

			// Step 2: Reconcile orgs → circles + group folders
			$this->reconcileOrganizations($kcOrgs, $dryRun, $result);

			// Step 3: Reconcile groups → Nextcloud groups
			if (!$repairOnly) {
				$this->reconcileGroups($kcGroups, $dryRun, $result);
			}

			// Step 4: Reconcile group → folder permissions
			if (!$repairOnly) {
				$this->reconcileGroupFolderPermissions($kcGroups, $dryRun, $result);
			}

			// Step 5: Cleanup orphaned resources
			$this->cleanupOrphanedResources($kcOrgs, $kcGroups, $dryRun, $result);

		} catch (Throwable $e) {
			$result['errors'][] = 'Fatal error: ' . $e->getMessage();
			$this->logger->error('Reconciliation failed', ['exception' => $e]);
		}

		return $result;
	}

	/**
	 * Resolve a Keycloak user ID (sub claim) to a Nextcloud IUser.
	 * Uses LocalIdService to compute the Nextcloud user_id from provider ID + Keycloak sub,
	 * since the mapping table stores hashed IDs (e.g., sha256("{providerId}_0_{sub}")).
	 *
	 * Returns null if the user hasn't logged in to Nextcloud yet.
	 */
	private function resolveKeycloakUser(string $keycloakSub, string $username): ?\OCP\IUser {
		// Compute the Nextcloud user_id using the same algorithm as login-time provisioning
		$nextcloudUserId = $this->localIdService->getId($this->providerId, $keycloakSub);
		if (strlen($nextcloudUserId) > 64) {
			$nextcloudUserId = hash('sha256', $nextcloudUserId);
		}

		if (!$this->userMapper->userExists($nextcloudUserId)) {
			$this->logger->info("Skipping user \"{$username}\" (Keycloak sub: {$keycloakSub}) — not yet provisioned in Nextcloud");
			return null;
		}

		$user = $this->userManager->get($nextcloudUserId);
		if ($user === null) {
			$this->logger->info("Skipping user \"{$username}\" — mapping exists but IUser not found");
			return null;
		}

		return $user;
	}

	private function reconcileOrganizations(array $kcOrgs, bool $dryRun, array &$result): void {
		if (!$this->circlesService->isCirclesEnabled()) {
			$result['warnings'][] = 'Circles app not enabled — skipping org→circle sync';
			return;
		}

		foreach ($kcOrgs as $org) {
			$orgName = $org['name'];
			$orgAlias = $org['alias'] ?? $orgName;

			// Check for folder name override
			$folderName = KeycloakAdminService::getAttribute($org, 'nextcloud:folder_name') ?? $orgName;
			$quotaStr = KeycloakAdminService::getAttribute($org, 'nextcloud:folder_quota');
			$quotaBytes = GroupFoldersService::parseQuota($quotaStr);

			// 1. Circle
			$circle = $this->circlesService->getOrCreateCircle($orgName, $orgName);
			if ($circle === null) {
				// Try alias as fallback
				$circle = $this->circlesService->getOrCreateCircle($orgAlias, $orgName);
			}

			if ($circle === null && $dryRun) {
				$result['actions'][] = ['type' => 'create_circle', 'name' => $orgName];
			} elseif ($circle === null) {
				$result['errors'][] = "Failed to create/find circle for org: {$orgName}";
				continue;
			}

			// 2. Group Folder
			if ($this->groupFoldersService->isGroupFoldersEnabled()) {
				$folder = $this->groupFoldersService->findFolderByName($folderName);

				if ($folder === null) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'create_folder', 'name' => $folderName, 'quota' => $quotaStr ?? '250 GB'];
					} else {
						$folderId = $this->groupFoldersService->createFolder($folderName);
						if ($folderId !== null) {
							$this->groupFoldersService->setQuota($folderId, $quotaBytes);
							// Link circle to folder
							if ($circle !== null) {
								$this->groupFoldersService->addCircle($folderId, $circle->getSingleId());
							}
							$result['actions'][] = ['type' => 'create_folder', 'name' => $folderName, 'id' => $folderId];
						}
					}
				} else {
					$folderId = $folder['id'];
					// Update quota if changed
					$currentQuota = $folder['quota'] ?? -3;
					if ($currentQuota !== $quotaBytes) {
						if ($dryRun) {
							$result['actions'][] = ['type' => 'set_quota', 'folder' => $folderName, 'quota' => $quotaStr ?? '250 GB'];
						} else {
							$this->groupFoldersService->setQuota($folderId, $quotaBytes);
							$result['actions'][] = ['type' => 'set_quota', 'folder' => $folderName];
						}
					}

					// Ensure circle is linked to folder
					if ($circle !== null) {
						$circleId = $circle->getSingleId();
						$folderCircles = $this->getFolderCircleIds($folder);
						if (!in_array($circleId, $folderCircles)) {
							if ($dryRun) {
								$result['actions'][] = ['type' => 'link_circle_folder', 'circle' => $orgName, 'folder' => $folderName];
							} else {
								$this->groupFoldersService->addCircle($folderId, $circleId);
								$result['actions'][] = ['type' => 'link_circle_folder', 'circle' => $orgName, 'folder' => $folderName];
							}
						}
					}
				}
			}

			// 3. Sync members (also report planned members in dry-run for new circles)
			if ($circle !== null) {
				$this->syncCircleMembers($org, $circle, $dryRun, $result);
			} elseif ($dryRun) {
				// Circle doesn't exist yet, but report planned member additions
				$kcMembers = $this->keycloakAdmin->getOrganizationMembers($org['id']);
				foreach ($kcMembers as $kcMember) {
					$user = $this->resolveKeycloakUser($kcMember['id'], $kcMember['username'] ?? $kcMember['id']);
					if ($user !== null) {
						$result['actions'][] = ['type' => 'add_circle_member', 'circle' => $org['name'], 'user' => $user->getUID()];
					}
				}
			}
		}
	}

	private function syncCircleMembers(array $org, object $circle, bool $dryRun, array &$result): void {
		$kcMembers = $this->keycloakAdmin->getOrganizationMembers($org['id']);
		$currentMemberIds = $this->circlesService->getCircleMembers($circle);

		$desiredMemberIds = [];
		foreach ($kcMembers as $kcMember) {
			$user = $this->resolveKeycloakUser($kcMember['id'], $kcMember['username'] ?? $kcMember['id']);
			if ($user !== null) {
				$desiredMemberIds[] = $user->getUID();
				if (!in_array($user->getUID(), $currentMemberIds)) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'add_circle_member', 'circle' => $org['name'], 'user' => $user->getUID()];
					} else {
						$this->circlesService->addMember($circle, $user);
						$result['actions'][] = ['type' => 'add_circle_member', 'circle' => $org['name'], 'user' => $user->getUID()];
					}
				}
			}
		}

		// Remove members not in Keycloak org
		foreach ($currentMemberIds as $memberId) {
			if (!in_array($memberId, $desiredMemberIds)) {
				$user = $this->userManager->get($memberId);
				if ($user !== null) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'remove_circle_member', 'circle' => $org['name'], 'user' => $memberId];
					} else {
						$this->circlesService->removeMember($circle, $user);
						$result['actions'][] = ['type' => 'remove_circle_member', 'circle' => $org['name'], 'user' => $memberId];
					}
				}
			}
		}
	}

	private function reconcileGroups(array $kcGroups, bool $dryRun, array &$result): void {
		foreach ($kcGroups as $kcGroup) {
			$groupName = $kcGroup['name'];

			if (in_array($groupName, self::PROTECTED_GROUPS)) {
				continue;
			}

			// Create group if needed
			if (!$this->groupManager->groupExists($groupName)) {
				if ($dryRun) {
					$result['actions'][] = ['type' => 'create_group', 'name' => $groupName];
				} else {
					$group = $this->groupManager->createGroup($groupName);
					if ($group !== null) {
						$group->setDisplayName($groupName);
						$result['actions'][] = ['type' => 'create_group', 'name' => $groupName];
					}
				}
			}

			// Sync members (also report planned members in dry-run for new groups)
			if ($this->groupManager->groupExists($groupName)) {
				$this->syncGroupMembers($kcGroup, $dryRun, $result);
			} elseif ($dryRun) {
				// Group doesn't exist yet, but report planned member additions
				$kcMembers = $this->keycloakAdmin->getGroupMembers($kcGroup['id']);
				foreach ($kcMembers as $kcMember) {
					$user = $this->resolveKeycloakUser($kcMember['id'], $kcMember['username'] ?? $kcMember['id']);
					if ($user !== null) {
						$result['actions'][] = ['type' => 'add_group_member', 'group' => $groupName, 'user' => $user->getUID()];
					}
				}
			}
		}
	}

	private function syncGroupMembers(array $kcGroup, bool $dryRun, array &$result): void {
		$groupName = $kcGroup['name'];
		$group = $this->groupManager->get($groupName);
		if ($group === null) {
			return;
		}

		$kcMembers = $this->keycloakAdmin->getGroupMembers($kcGroup['id']);
		$currentMembers = $group->getUsers();
		$currentMemberIds = array_map(fn ($u) => $u->getUID(), $currentMembers);

		$desiredMemberIds = [];
		foreach ($kcMembers as $kcMember) {
			$user = $this->resolveKeycloakUser($kcMember['id'], $kcMember['username'] ?? $kcMember['id']);
			if ($user !== null) {
				$desiredMemberIds[] = $user->getUID();
				if (!in_array($user->getUID(), $currentMemberIds)) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'add_group_member', 'group' => $groupName, 'user' => $user->getUID()];
					} else {
						$group->addUser($user);
						$result['actions'][] = ['type' => 'add_group_member', 'group' => $groupName, 'user' => $user->getUID()];
					}
				}
			}
		}

		// Remove stale members
		foreach ($currentMemberIds as $memberId) {
			if (!in_array($memberId, $desiredMemberIds)) {
				$user = $this->userManager->get($memberId);
				if ($user !== null) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'remove_group_member', 'group' => $groupName, 'user' => $memberId];
					} else {
						$group->removeUser($user);
						$result['actions'][] = ['type' => 'remove_group_member', 'group' => $groupName, 'user' => $memberId];
					}
				}
			}
		}
	}

	private function reconcileGroupFolderPermissions(array $kcGroups, bool $dryRun, array &$result): void {
		if (!$this->groupFoldersService->isGroupFoldersEnabled()) {
			return;
		}

		foreach ($kcGroups as $kcGroup) {
			$folderAccessStr = KeycloakAdminService::getAttribute($kcGroup, 'nextcloud:folder_access');
			$desiredFolders = GroupFoldersService::parseFolderAccess($folderAccessStr);

			if (empty($desiredFolders)) {
				continue;
			}

			$groupName = $kcGroup['name'];

			foreach ($desiredFolders as $folderName) {
				$folder = $this->groupFoldersService->findFolderByName($folderName);
				if ($folder === null) {
					$result['warnings'][] = "Group {$groupName} references folder \"{$folderName}\" which does not exist";
					continue;
				}

				$folderGroups = $this->getFolderGroupIds($folder);
				if (!in_array($groupName, $folderGroups)) {
					if ($dryRun) {
						$result['actions'][] = ['type' => 'grant_folder_access', 'group' => $groupName, 'folder' => $folderName];
					} else {
						$this->groupFoldersService->addGroup($folder['id'], $groupName);
						$result['actions'][] = ['type' => 'grant_folder_access', 'group' => $groupName, 'folder' => $folderName];
					}
				}
			}
		}
	}

	private function cleanupOrphanedResources(array $kcOrgs, array $kcGroups, bool $dryRun, array &$result): void {
		// Detect corrupted circle names (singleId pattern: base64-like, no spaces, 20+ chars)
		$circles = $this->circlesService->getAllCircles();
		$orgNames = array_map(fn ($o) => $o['name'], $kcOrgs);
		$orgAliases = array_map(fn ($o) => $o['alias'] ?? $o['name'], $kcOrgs);

		foreach ($circles as $circle) {
			$displayName = $circle->getDisplayName();
			// Detect base64-like singleId used as display name
			if (preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $displayName) && !str_contains($displayName, ' ')) {
				$result['warnings'][] = "Circle \"{$displayName}\" has a corrupted name (looks like a singleId)";
			}
		}

		// Detect orphaned group folders (no groups or circles assigned)
		if ($this->groupFoldersService->isGroupFoldersEnabled()) {
			$folders = $this->groupFoldersService->listFolders();
			foreach ($folders as $folderId => $folder) {
				$groups = $this->getFolderGroupIds($folder);
				$folderCircles = $this->getFolderCircleIds($folder);
				if (empty($groups) && empty($folderCircles)) {
					$result['warnings'][] = "Orphaned group folder: \"{$folder['mount_point']}\" (ID: {$folderId}) — no groups or circles assigned";
				}
			}
		}

		// Detect stale Nextcloud groups (no Keycloak match)
		$kcGroupNames = array_map(fn ($g) => $g['name'], $kcGroups);
		$ncGroups = $this->groupManager->search('');
		foreach ($ncGroups as $ncGroup) {
			$gid = $ncGroup->getGID();
			if (in_array($gid, self::PROTECTED_GROUPS)) {
				continue;
			}
			if (!in_array($gid, $kcGroupNames) && !in_array($gid, $orgNames) && !in_array($gid, $orgAliases)) {
				$result['warnings'][] = "Stale Nextcloud group: \"{$gid}\" — no matching Keycloak group or organization";
			}
		}
	}

	/**
	 * Extract group IDs from a folder's group assignments.
	 */
	private function getFolderGroupIds(array $folder): array {
		$groups = $folder['groups'] ?? [];
		return array_keys($groups);
	}

	/**
	 * Extract circle IDs from a folder's circle assignments.
	 */
	private function getFolderCircleIds(array $folder): array {
		// GroupFolders stores circles under 'circles' key (if Teams integration is enabled)
		$circles = $folder['circles'] ?? [];
		return array_keys($circles);
	}
}
