<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method \string getUserId()
 * @method \void setUserId(string $userId)
 * @method \string getDisplayName()
 * @method \void setDisplayName(string $displayName)
 * @method \int|null getProviderId()
 * @method \void setProviderId(?int $providerId)
 * @method \string|null getSub()
 * @method \void setSub(?string $sub)
 */
class User extends Entity {

	/** @var string */
	protected $userId;

	/** @var string */
	protected $displayName;

	/**
	 * The provider this user was provisioned from, kept alongside the
	 * (possibly hashed) userId so the same person can be recognized again
	 * independently of the user_id-generation setting.
	 * @var int|null
	 */
	protected $providerId;

	/**
	 * The OIDC subject claim this user was provisioned from. See $providerId.
	 * @var string|null
	 */
	protected $sub;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('displayName', Types::STRING);
		$this->addType('providerId', Types::INTEGER);
		$this->addType('sub', Types::STRING);
	}
}
