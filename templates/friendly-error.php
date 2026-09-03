<?php
/**
 * SPDX-FileCopyrightText: 2026 Junovy Hosting
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The two inline icons are "rocket" and "lock" from Phosphor Icons (https://phosphoricons.com),
 * duotone weight, MIT licensed (see LICENSES/MIT.txt).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCP\Defaults $theme
 */
$kind = $_['kind'];
$icons = [
	'expired' => '<path d="M94.81,192,65.36,214.24a8,8,0,0,1-12.81-4.51L40.19,154.1a8,8,0,0,1,1.66-6.86l30.31-36.33C71,134.25,76.7,161.43,94.81,192Zm119.34-44.76-30.31-36.33c1.21,23.34-4.54,50.52-22.65,81.09l29.45,22.24a8,8,0,0,0,12.81-4.51l12.36-55.63A8,8,0,0,0,214.15,147.24Z" opacity="0.2"/><path d="M152,224a8,8,0,0,1-8,8H112a8,8,0,0,1,0-16h32A8,8,0,0,1,152,224ZM128,112a12,12,0,1,0-12-12A12,12,0,0,0,128,112Zm95.62,43.83-12.36,55.63a16,16,0,0,1-25.51,9.11L158.51,200h-61L70.25,220.57a16,16,0,0,1-25.51-9.11L32.38,155.83a16.09,16.09,0,0,1,3.32-13.71l28.56-34.26a123.07,123.07,0,0,1,8.57-36.67c12.9-32.34,36-52.63,45.37-59.85a16,16,0,0,1,19.6,0c9.34,7.22,32.47,27.51,45.37,59.85a123.07,123.07,0,0,1,8.57,36.67l28.56,34.26A16.09,16.09,0,0,1,223.62,155.83ZM99.43,184h57.14c21.12-37.54,25.07-73.48,11.74-106.88C156.55,47.64,134.49,29,128,24c-6.51,5-28.57,23.64-40.33,53.12C74.36,110.52,78.31,146.46,99.43,184Zm-15,5.85Q68.28,160.5,64.83,132.16L48,152.36,60.36,208l.18-.13ZM208,152.36l-16.83-20.2q-3.42,28.28-19.56,57.69l23.85,18,.18.13Z"/>',
	'not_shared' => '<path d="M216,96V208a8,8,0,0,1-8,8H48a8,8,0,0,1-8-8V96a8,8,0,0,1,8-8H208A8,8,0,0,1,216,96Z" opacity="0.2"/><path d="M208,80H176V56a48,48,0,0,0-96,0V80H48A16,16,0,0,0,32,96V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V96A16,16,0,0,0,208,80ZM96,56a32,32,0,0,1,64,0V80H96ZM208,208H48V96H208V208Zm-68-56a12,12,0,1,1-12-12A12,12,0,0,1,140,152Z"/>',
	// "warning-circle", duotone
	'trouble' => '<path d="M224,128a96,96,0,1,1-96-96A96,96,0,0,1,224,128Z" opacity="0.2"/><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm-8-80V80a8,8,0,0,1,16,0v56a8,8,0,0,1-16,0Zm20,36a12,12,0,1,1-12-12A12,12,0,0,1,140,172Z"/>',
];
$icon = $icons[$kind] ?? $icons['trouble'];
?>
<div id="junovy-oidc-error"
	class="junovy-oidc-error junovy-oidc-error--<?php p($kind); ?>"
	data-auto-continue="<?php p($_['autoContinue'] ? '1' : '0'); ?>"
	data-continue-url="<?php p($_['continueUrl']); ?>"
	data-countdown="<?php p((string)$_['autoContinueSeconds']); ?>">
	<div class="junovy-oidc-error__logo" role="img" aria-label="<?php p($theme->getName()); ?>"></div>
	<div class="junovy-oidc-error__icon" aria-hidden="true">
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor"><?php print_unescaped($icon); ?></svg>
	</div>
	<h2 class="junovy-oidc-error__title"><?php p($_['title']); ?></h2>
	<p class="junovy-oidc-error__body"><?php p($_['body']); ?></p>
	<a class="junovy-oidc-error__primary" href="<?php p($_['primary']['href']); ?>"><?php p($_['primary']['label']); ?></a>
	<?php if (!empty($_['secondary'])) : ?>
		<a class="junovy-oidc-error__secondary" href="<?php p($_['secondary']['href']); ?>"><?php p($_['secondary']['label']); ?></a>
	<?php endif; ?>
	<?php if ($_['autoContinue']) : ?>
		<div class="junovy-oidc-error__countdown" data-countdown-row hidden>
			<div class="junovy-oidc-error__progress" aria-hidden="true"><div class="junovy-oidc-error__progress-bar" data-progress></div></div>
			<p class="junovy-oidc-error__countdown-text">
				<span aria-live="polite" data-countdown-text><?php p($_['countdownLabel']); ?></span>
				<span aria-hidden="true" data-countdown-separator>·</span>
				<button type="button" class="junovy-oidc-error__stay" data-stay><?php p($_['stayLabel']); ?></button>
			</p>
		</div>
	<?php endif; ?>
	<?php if (!empty($_['supportUrl'])) : ?>
		<p class="junovy-oidc-error__support"><?php p($_['supportLabel']); ?> <a href="<?php p($_['supportUrl']); ?>"><?php p($_['supportLinkLabel']); ?></a></p>
	<?php endif; ?>
	<p class="junovy-oidc-error__meta"><?php p($l->t('For support: ref %1$s · %2$s', [$_['ref'], $_['timestamp']])); ?></p>
</div>
