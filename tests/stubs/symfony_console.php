<?php

/**
 * SPDX-FileCopyrightText: 2026 Junovy GmbH and Junovy contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal Symfony Console stubs for unit testing without full Symfony dependency.
 */

namespace Symfony\Component\Console\Input {
	if (!interface_exists(InputInterface::class)) {
		interface InputInterface {
			public function getArgument(string $name);
			public function getOption(string $name);
			public function hasOption(string $name): bool;
		}
	}

	if (!class_exists(InputOption::class)) {
		class InputOption {
			public const VALUE_NONE = 1;
			public const VALUE_REQUIRED = 2;
			public const VALUE_OPTIONAL = 4;
			public const VALUE_IS_ARRAY = 8;
		}
	}

	if (!class_exists(InputDefinition::class)) {
		class InputDefinition {
		}
	}
}

namespace Symfony\Component\Console\Output {
	if (!interface_exists(OutputInterface::class)) {
		interface OutputInterface {
			public function writeln($messages, int $options = 0);
			public function write($messages, bool $newline = false, int $options = 0);
			public function isVerbose(): bool;
		}
	}
}

namespace Symfony\Component\Console {
	if (!class_exists(Application::class)) {
		class Application {
		}
	}
}

namespace Stecman\Component\Symfony\Console\BashCompletion {
	if (!class_exists(CompletionContext::class)) {
		class CompletionContext {
		}
	}
}
