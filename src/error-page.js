/**
 * SPDX-FileCopyrightText: 2026 Junovy Hosting
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { n, t } from '@nextcloud/l10n'

const APP_ID = 'junovy_user_oidc'
// A broken session store would send the user straight back here; only auto-continue once per window.
const GUARD_KEY = 'junovy_user_oidc:auto-continue-at'
const GUARD_WINDOW_MS = 10 * 60 * 1000

/**
 * Whether a previous auto-continue happened recently enough that we should not try again on our own.
 *
 * @return {boolean}
 */
function recentlyAutoContinued() {
	try {
		const last = Number(window.localStorage.getItem(GUARD_KEY) || 0)
		return Date.now() - last < GUARD_WINDOW_MS
	} catch (e) {
		return false
	}
}

/**
 * Remember that we auto-continued, so the next visit shows the button only.
 */
function rememberAutoContinue() {
	try {
		window.localStorage.setItem(GUARD_KEY, String(Date.now()))
	} catch (e) {
		// storage unavailable (private mode, blocked); the guard just does not apply
	}
}

/**
 * Countdown that pauses while the card has hover or keyboard focus and can be cancelled with "Stay here".
 *
 * @param {HTMLElement} root the error page root
 */
function startCountdown(root) {
	const row = root.querySelector('[data-countdown-row]')
	const text = root.querySelector('[data-countdown-text]')
	const separator = root.querySelector('[data-countdown-separator]')
	const progress = root.querySelector('[data-progress]')
	const stay = root.querySelector('[data-stay]')
	const url = root.dataset.continueUrl
	const total = Math.max(1, parseInt(root.dataset.countdown, 10) || 5)
	if (!row || !text || !stay || !url) {
		return
	}

	let remaining = total
	let paused = false
	let timer = null

	const render = () => {
		text.textContent = n(APP_ID, 'Continuing in %n second', 'Continuing in %n seconds', remaining)
		if (progress) {
			progress.style.width = `${Math.round(((total - remaining) / total) * 100)}%`
		}
	}

	const stop = () => {
		if (timer !== null) {
			window.clearInterval(timer)
			timer = null
		}
	}

	const tick = () => {
		if (paused) {
			return
		}
		remaining -= 1
		render()
		if (remaining <= 0) {
			stop()
			rememberAutoContinue()
			window.location.assign(url)
		}
	}

	const pause = () => { paused = true }
	const resume = (event) => {
		// keep pausing while focus merely moves within the card
		if (event && event.type === 'focusout' && event.relatedTarget && root.contains(event.relatedTarget)) {
			return
		}
		paused = false
	}

	root.addEventListener('mouseenter', pause)
	root.addEventListener('mouseleave', resume)
	root.addEventListener('focusin', pause)
	root.addEventListener('focusout', resume)

	stay.addEventListener('click', () => {
		stop()
		stay.hidden = true
		if (separator) {
			separator.hidden = true
		}
		if (progress) {
			progress.parentElement.hidden = true
		}
		text.textContent = t(APP_ID, "We'll wait here.")
	})

	row.hidden = false
	render()
	timer = window.setInterval(tick, 1000)
}

const root = document.getElementById('junovy-oidc-error')
if (root && root.dataset.autoContinue === '1' && !recentlyAutoContinued()) {
	startCountdown(root)
}
