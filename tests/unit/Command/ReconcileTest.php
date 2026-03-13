<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Junovy GmbH and Junovy contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Tests\Unit\Command;

use OCA\UserOIDC\Command\Reconcile;
use OCA\UserOIDC\Db\Provider;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcileTest extends TestCase {

	private Reconcile $command;
	private ReconciliationService $reconciliationService;
	private ProviderMapper $providerMapper;

	/** @var string[] Captured output lines from the most recent command execution */
	private array $outputLines = [];

	protected function setUp(): void {
		$this->reconciliationService = $this->createMock(ReconciliationService::class);
		$this->providerMapper = $this->createMock(ProviderMapper::class);
		$this->outputLines = [];

		$this->command = new Reconcile(
			$this->reconciliationService,
			$this->providerMapper,
		);
	}

	private function createProvider(int $id, string $identifier): Provider {
		$provider = new Provider();
		$provider->setId($id);
		$provider->setIdentifier($identifier);
		return $provider;
	}

	private function createInput(array $options = []): InputInterface {
		$defaults = [
			'dry-run' => false,
			'repair' => false,
			'provider-id' => null,
		];
		$merged = array_merge($defaults, $options);

		$input = $this->createMock(InputInterface::class);
		$input->method('getOption')->willReturnCallback(function (string $name) use ($merged) {
			return $merged[$name] ?? null;
		});
		return $input;
	}

	private function createCapturingOutput(): OutputInterface {
		$output = $this->createMock(OutputInterface::class);
		$output->method('writeln')->willReturnCallback(function ($line): void {
			$this->outputLines[] = $line;
		});
		return $output;
	}

	private function executeCommand(InputInterface $input, OutputInterface $output): int {
		$method = new \ReflectionMethod(Reconcile::class, 'execute');
		return $method->invoke($this->command, $input, $output);
	}

	public function testDryRunPassesFlagToService(): void {
		$provider = $this->createProvider(1, 'keycloak');
		$this->providerMapper->method('getProviders')->willReturn([$provider]);

		$this->reconciliationService->expects($this->once())
			->method('reconcile')
			->with(1, true, false)
			->willReturn(['actions' => [], 'warnings' => [], 'errors' => [], 'dry_run' => true]);

		$input = $this->createInput(['dry-run' => true]);
		$output = $this->createCapturingOutput();
		$result = $this->executeCommand($input, $output);

		$this->assertEquals(0, $result);
		$outputText = implode("\n", $this->outputLines);
		$this->assertStringContainsString('DRY RUN', $outputText);
	}

	public function testExitCode1OnErrors(): void {
		$provider = $this->createProvider(1, 'keycloak');
		$this->providerMapper->method('getProviders')->willReturn([$provider]);

		$this->reconciliationService->method('reconcile')
			->willReturn([
				'actions' => [],
				'warnings' => [],
				'errors' => ['Something failed'],
				'dry_run' => false,
			]);

		$input = $this->createInput();
		$output = $this->createCapturingOutput();
		$result = $this->executeCommand($input, $output);

		$this->assertEquals(1, $result);
	}

	public function testExitCode2WhenNoProviders(): void {
		$this->providerMapper->method('getProviders')->willReturn([]);

		$input = $this->createInput();
		$output = $this->createCapturingOutput();
		$result = $this->executeCommand($input, $output);

		$this->assertEquals(2, $result);
		$outputText = implode("\n", $this->outputLines);
		$this->assertStringContainsString('No OIDC providers', $outputText);
	}

	public function testProviderIdOption(): void {
		$this->reconciliationService->expects($this->once())
			->method('reconcile')
			->with(42, false, false)
			->willReturn(['actions' => [], 'warnings' => [], 'errors' => [], 'dry_run' => false]);

		$input = $this->createInput(['provider-id' => '42']);
		$output = $this->createCapturingOutput();
		$result = $this->executeCommand($input, $output);

		$this->assertEquals(0, $result);
	}
}
