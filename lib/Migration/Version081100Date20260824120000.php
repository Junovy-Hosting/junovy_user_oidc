<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\Attributes\ColumnType;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

//#[AddColumn(table: 'user_oidc', name: 'provider_id', type: ColumnType::INTEGER, description: 'Store the id of the provider for this user')]
//#[AddColumn(table: 'user_oidc', name: 'sub', type: ColumnType::STRING, description: 'Store the sub for this user')]
class Version081100Date20260824120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		$table = $schema->getTable('jnvy_oidc');
		if (!$table->hasColumn('provider_id')) {
			$table->addColumn('provider_id', Types::INTEGER, [
				'notnull' => false,
				'length' => 4,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('sub')) {
			$table->addColumn('sub', Types::STRING, [
				'notnull' => false,
				'length' => 256,
			]);
			$changed = true;
		}
		if (!$table->hasIndex('jnvy_oidc_prov_sub')) {
			// unique: this is now the primary lookup key for an existing
			// identity, not just an auxiliary index
			$table->addUniqueIndex(['provider_id', 'sub'], 'jnvy_oidc_prov_sub');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
