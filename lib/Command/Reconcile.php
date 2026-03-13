<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Junovy GmbH and Junovy contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Command;

use OC\Core\Command\Base;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ReconciliationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Reconcile extends Base {

	public function __construct(
		private ReconciliationService $reconciliationService,
		private ProviderMapper $providerMapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('junovy_user_oidc:reconcile')
			->setDescription('Reconcile Keycloak organizations and groups with Nextcloud Circles, Groups, and Group Folders')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without applying')
			->addOption('repair', null, InputOption::VALUE_NONE, 'Only fix orphaned/corrupted resources (still requires Keycloak credentials)')
			->addOption('provider-id', 'p', InputOption::VALUE_REQUIRED, 'OIDC provider ID (if omitted, uses the first registered provider)');
		// Note: --verbose/-v is built into Symfony Console, use $output->isVerbose()
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = $input->getOption('dry-run');
		$repairOnly = $input->getOption('repair');

		// Resolve provider ID
		$providerIdOption = $input->getOption('provider-id');
		if ($providerIdOption !== null) {
			$providerId = (int)$providerIdOption;
		} else {
			// Use the first registered provider
			$providers = $this->providerMapper->getProviders();
			if (empty($providers)) {
				$output->writeln('<error>No OIDC providers configured. Register one first with occ junovy_user_oidc:provider</error>');
				return 2;
			}
			$providerId = $providers[0]->getId();
			$output->writeln('<info>Using provider: ' . $providers[0]->getIdentifier() . ' (ID: ' . $providerId . ')</info>');
		}

		if ($dryRun) {
			$output->writeln('<info>[DRY RUN] No changes will be applied</info>');
		}

		if ($repairOnly) {
			$output->writeln('<info>Running in repair mode — only fixing corrupted/orphaned resources</info>');
		}

		try {
			$result = $this->reconciliationService->reconcile($providerId, $dryRun, $repairOnly);
		} catch (\Throwable $e) {
			$output->writeln('<error>Fatal error: ' . $e->getMessage() . '</error>');
			return 2;
		}

		// Print actions
		foreach ($result['actions'] as $action) {
			$prefix = $dryRun ? '[DRY RUN] Would ' : '';
			$line = $this->formatAction($action, $prefix);
			$output->writeln($line);
		}

		// Print warnings
		foreach ($result['warnings'] as $warning) {
			$output->writeln('<comment>[WARN] ' . $warning . '</comment>');
		}

		// Print errors
		foreach ($result['errors'] as $error) {
			$output->writeln('<error>[ERROR] ' . $error . '</error>');
		}

		// Summary
		$actionCount = count($result['actions']);
		$warnCount = count($result['warnings']);
		$errorCount = count($result['errors']);
		$output->writeln('');
		$output->writeln("Summary: {$actionCount} actions, {$warnCount} warnings, {$errorCount} errors");

		if ($errorCount > 0) {
			return 1; // Partial failure
		}

		return 0;
	}

	private function formatAction(array $action, string $prefix): string {
		return match ($action['type']) {
			'create_circle' => $prefix . "create circle: \"{$action['name']}\"",
			'create_folder' => $prefix . "create group folder: \"{$action['name']}\" (quota: " . ($action['quota'] ?? 'default') . ')',
			'set_quota' => $prefix . "set quota on folder: \"{$action['folder']}\" → " . ($action['quota'] ?? 'updated'),
			'link_circle_folder' => $prefix . "link circle \"{$action['circle']}\" to folder \"{$action['folder']}\"",
			'add_circle_member' => $prefix . "add user \"{$action['user']}\" to circle \"{$action['circle']}\"",
			'remove_circle_member' => $prefix . "remove user \"{$action['user']}\" from circle \"{$action['circle']}\"",
			'create_group' => $prefix . "create group: \"{$action['name']}\"",
			'add_group_member' => $prefix . "add user \"{$action['user']}\" to group \"{$action['group']}\"",
			'remove_group_member' => $prefix . "remove user \"{$action['user']}\" from group \"{$action['group']}\"",
			'grant_folder_access' => $prefix . "grant group \"{$action['group']}\" access to folder \"{$action['folder']}\"",
			default => $prefix . json_encode($action),
		};
	}
}
