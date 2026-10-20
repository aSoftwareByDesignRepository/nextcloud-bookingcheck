// Dev-stack glue for e2e: occ runner, on-demand cron jobs, Mailhog polling.
// BookingCheck queues transactional mail through EmailQueueJob (60s cron) —
// specs must not wait on the cron tick; runJob() executes it directly.
const { execFileSync } = require('child_process')
const { resolve } = require('path')

const nextcloudRoot = resolve(__dirname, '../../../..')
const MAILHOG = (process.env.E2E_MAILHOG || 'http://127.0.0.1:8028').replace(/\/$/, '')

/** @param {string[]} occArgs */
function occ(occArgs) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...occArgs,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 })
}

/**
 * Resolve a background job's DB id by class suffix, e.g. 'EmailQueueJob'.
 * Matches the fully-qualified OCA\BookingCheck\ class — sibling apps ship
 * same-named job classes, and a bare substring pick can execute theirs.
 */
function jobId(classSuffix) {
	const out = occ(['background-job:list'])
	const fqcn = classSuffix.includes('\\')
		? classSuffix
		: `OCA\\BookingCheck\\BackgroundJob\\${classSuffix}`
	const line = out.split('\n').find((l) => l.includes(fqcn))
	const m = line && line.match(/\|\s*(\d+)\s*\|/)
	if (!m) {
		throw new Error(`no background job matching "${classSuffix}"`)
	}
	return m[1]
}

/**
 * Force-run a queued background job once (e.g. drain the mail outbox).
 * --force-execute is required: a plain execute is skipped when the job's
 * scheduled interval hasn't elapsed since the system cron's last run, which
 * silently defers delivery to the next cron tick and starves the mail poll.
 */
function runJob(classSuffix) {
	occ(['background-job:execute', '--force-execute', jobId(classSuffix)])
}

/**
 * Poll Mailhog until a message arrives whose decoded body contains `matcher`
 * (regex). `to` optionally pins the recipient — required when test mailboxes
 * are fixed strings, else a stale mail from an earlier run wins the match.
 * Bodies live in Content.Body for single-part mails, MIME.Parts[*].Body for
 * multipart; quoted-printable is decoded so URLs survive soft breaks.
 */
async function mailBody(matcher, { to = '', timeoutMs = 90_000 } = {}) {
	const deadline = Date.now() + timeoutMs
	for (;;) {
		const res = await fetch(`${MAILHOG}/api/v2/messages?limit=50`).catch(() => null)
		const data = res && res.ok ? await res.json() : null
		for (const item of data?.items ?? []) {
			if (to) {
				const rcpts = (item?.To ?? []).map((r) =>
					typeof r === 'string' ? r : `${r?.Mailbox ?? ''}@${r?.Domain ?? ''}`)
				const toHdr = [item?.Content?.Headers?.To ?? []].flat()
				if (![...rcpts, ...toHdr].join(' ').includes(to)) {
					continue
				}
			}
			const parts = item?.MIME?.Parts ?? []
			const raw = (item?.Content?.Body ?? '') + '\n'
				+ parts.map((p) => p?.Body ?? '').join('\n')
			const decoded = decodeQuotedPrintable(raw)
			if (matcher.test(decoded)) {
				return decoded
			}
		}
		if (Date.now() > deadline) {
			throw new Error(`mailhog: no message matching ${matcher} (to=${to || 'any'}) within ${timeoutMs}ms`)
		}
		await new Promise((r) => setTimeout(r, 1_500))
	}
}

/** Extract the first absolute URL matching `pattern` from a decoded body. */
function extractUrl(body, pattern) {
	const m = body.match(pattern)
	if (!m) {
		throw new Error(`pattern ${pattern} not found in mail body`)
	}
	return m[0].replace(/=\r?\n/g, '').replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
}

function decodeQuotedPrintable(s) {
	return s.replace(/=\r?\n/g, '').replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)))
}

module.exports = { occ, jobId, runJob, mailBody, extractUrl }
