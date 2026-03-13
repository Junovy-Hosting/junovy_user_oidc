<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Tests\Unit\Service;

use OCA\UserOIDC\Service\GroupFoldersService;
use PHPUnit\Framework\TestCase;

class GroupFoldersServiceTest extends TestCase {

	public function testParseQuota(): void {
		$this->assertEquals(10 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota('10 GB'));
		$this->assertEquals(250 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota('250 GB'));
		$this->assertEquals(1024 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota('1 TB'));
		$this->assertEquals(-3, GroupFoldersService::parseQuota('Unlimited'));
		$this->assertEquals(0, GroupFoldersService::parseQuota('None'));
		// Default when null
		$this->assertEquals(250 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota(null));
	}

	public function testParseQuotaCaseInsensitive(): void {
		$this->assertEquals(-3, GroupFoldersService::parseQuota('unlimited'));
		$this->assertEquals(0, GroupFoldersService::parseQuota('none'));
		$this->assertEquals(10 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota('10 gb'));
		$this->assertEquals(1024 * 1024 * 1024 * 1024, GroupFoldersService::parseQuota('1 tb'));
	}

	public function testParseFolderAccessAttribute(): void {
		$this->assertEquals(
			['Junovy Ops', 'Acme Corp'],
			GroupFoldersService::parseFolderAccess('Junovy Ops,Acme Corp')
		);
		$this->assertEquals(
			['Junovy Ops'],
			GroupFoldersService::parseFolderAccess('Junovy Ops')
		);
		$this->assertEquals([], GroupFoldersService::parseFolderAccess(null));
		$this->assertEquals([], GroupFoldersService::parseFolderAccess(''));
	}
}
