<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service to interact with the Nextcloud GroupFolders app.
 * Prefers the PHP FolderManager API when available, falls back to OCS REST.
 */
class GroupFoldersService {

	private const GROUPFOLDERS_APP_ID = 'groupfolders';
	private const DEFAULT_QUOTA_BYTES = 268435456000; // 250 GB

	/** @var object|null FolderManager instance */
	private ?object $folderManager = null;
	private bool $initialized = false;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function isGroupFoldersEnabled(): bool {
		return $this->appManager->isEnabledForUser(self::GROUPFOLDERS_APP_ID);
	}

	private function getFolderManager(): ?object {
		if ($this->initialized) {
			return $this->folderManager;
		}

		$this->initialized = true;

		if (!$this->isGroupFoldersEnabled()) {
			$this->logger->debug('GroupFolders app is not enabled');
			return null;
		}

		try {
			if (class_exists('\OCA\GroupFolders\Folder\FolderManager')) {
				$this->folderManager = \OC::$server->get(\OCA\GroupFolders\Folder\FolderManager::class);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Failed to initialize FolderManager', ['exception' => $e]);
		}

		return $this->folderManager;
	}

	/**
	 * List all group folders with their groups and quotas.
	 *
	 * @return array Array of folder data keyed by folder ID
	 */
	public function listFolders(): array {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return [];
		}

		try {
			// Try multiple approaches — the root storage ID parameter varies across GroupFolders versions
			$folders = $manager->getAllFoldersWithSize(-1);
			if (!empty($folders)) {
				return $folders;
			}

			// Fallback: try without size info if getAllFoldersWithSize returned empty
			if (method_exists($manager, 'getAllFolders')) {
				$folders = $manager->getAllFolders();
				if (!empty($folders)) {
					$this->logger->debug('listFolders: getAllFoldersWithSize(-1) returned empty, fell back to getAllFolders()');
					return $folders;
				}
			}

			return [];
		} catch (Throwable $e) {
			$this->logger->warning('Failed to list group folders', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Create a group folder.
	 *
	 * @return int|null The new folder ID, or null on failure
	 */
	public function createFolder(string $name): ?int {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return null;
		}

		try {
			$folderId = $manager->createFolder($name);
			$this->logger->info("Created group folder: {$name} (ID: {$folderId})");
			return $folderId;
		} catch (Throwable $e) {
			$this->logger->warning("Failed to create group folder: {$name}", ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Set quota on a group folder.
	 *
	 * @param int $folderId Folder ID
	 * @param int $quotaBytes Quota in bytes, -3 for unlimited, 0 for none
	 */
	public function setQuota(int $folderId, int $quotaBytes): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->setFolderQuota($folderId, $quotaBytes);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to set quota on folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Add a Nextcloud group to a group folder.
	 */
	public function addGroup(int $folderId, string $groupId): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->addApplicableGroup($folderId, $groupId);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to add group {$groupId} to folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Remove a Nextcloud group from a group folder.
	 */
	public function removeGroup(int $folderId, string $groupId): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->removeApplicableGroup($folderId, $groupId);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to remove group {$groupId} from folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Add a circle (team) to a group folder.
	 */
	public function addCircle(int $folderId, string $circleId): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->addApplicableCircle($folderId, $circleId);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to add circle {$circleId} to folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Remove a circle (team) from a group folder.
	 */
	public function removeCircle(int $folderId, string $circleId): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->removeApplicableCircle($folderId, $circleId);
		} catch (Throwable $e) {
			$this->logger->warning("Failed to remove circle {$circleId} from folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Rename a group folder.
	 */
	public function renameFolder(int $folderId, string $newName): void {
		$manager = $this->getFolderManager();
		if ($manager === null) {
			return;
		}

		try {
			$manager->renameFolder($folderId, $newName);
			$this->logger->info("Renamed group folder {$folderId} to: {$newName}");
		} catch (Throwable $e) {
			$this->logger->warning("Failed to rename folder {$folderId}", ['exception' => $e]);
		}
	}

	/**
	 * Find a group folder by name (case-insensitive, whitespace-trimmed).
	 *
	 * @return array|null Folder data or null if not found
	 */
	public function findFolderByName(string $name): ?array {
		$folders = $this->listFolders();
		$normalizedName = strtolower(trim($name));
		foreach ($folders as $id => $folder) {
			$mountPoint = strtolower(trim($folder['mount_point'] ?? ''));
			if ($mountPoint === $normalizedName) {
				$folder['id'] = $id;
				return $folder;
			}
		}
		return null;
	}

	/**
	 * Parse a human-readable quota string into bytes.
	 *
	 * @param string|null $quota e.g. "250 GB", "1 TB", "Unlimited", "None", or null
	 * @return int Bytes, -3 for unlimited, 0 for none
	 */
	public static function parseQuota(?string $quota): int {
		if ($quota === null || trim($quota) === '') {
			return self::DEFAULT_QUOTA_BYTES;
		}

		$quota = trim($quota);
		$lower = strtolower($quota);

		if ($lower === 'unlimited') {
			return -3;
		}
		if ($lower === 'none') {
			return 0;
		}

		if (preg_match('/^(\d+)\s*(gb|tb)$/i', $quota, $matches)) {
			$value = (int)$matches[1];
			$unit = strtolower($matches[2]);
			if ($unit === 'gb') {
				return $value * 1073741824; // 1024^3
			}
			if ($unit === 'tb') {
				return $value * 1099511627776; // 1024^4
			}
		}

		return self::DEFAULT_QUOTA_BYTES;
	}

	/**
	 * Parse a comma-separated folder access attribute into an array of folder names.
	 */
	public static function parseFolderAccess(?string $value): array {
		if ($value === null || trim($value) === '') {
			return [];
		}
		return array_map('trim', explode(',', $value));
	}
}
