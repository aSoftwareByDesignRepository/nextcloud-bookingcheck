#!/usr/bin/env node
/**
 * Contract: rules that match <select> must not use the bare `background:`
 * shorthand — it resets the chevron's image/position/repeat/size to initial.
 * Regression of the 2026-11-04 intake seed
 * bookingcheck-intake-20261104T231136Z-shell-chrome-synced-select-shorthand
 * (required_field_or_step_undiscoverable): the synced shared-pack copy
 * css/common/shell-chrome.css painted `background: var(--bck-bg-card);`
 * inside `.bck-scope-strip__select` — wiping the native select chevron on
 * any scope-strip picker the rule is ever applied to. The family .tpl was
 * fixed 2026-11-04 (@765ebafebc); generated copies rendered before it were
 * infected (deskcheck r15-delta).
 *
 * Leg 1 (segment-literal): any selector holding `select` bounded by
 * non-letter/digit (select, .bck-select, .form-select,
 * .bck-scope-strip__select — but NOT .bck-selected/.bck-selector) must not
 * use `background:`. `_` and `-` count as delimiters; letters/digits do not.
 * Leg 2 (class sweep): classes provably applied to <select> in js/ +
 * templates/ must not carry the shorthand in ANY rule using them.
 * Leg 3: the form-controls.css chevron rule keeps image + position +
 * repeat + size longhands and appearance:none.
 * (Mirrors deskcheck/maintenancecheck/ticketcheck contracts; leg 1 widened
 * from \bselect\b to segment match because `__select` has no word
 * boundary.)
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync, readdirSync } = require('node:fs')
const { join, relative } = require('node:path')

const appRoot = join(__dirname, '..', '..')
const cssRoot = join(appRoot, 'css')

function cssFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			return cssFiles(path)
		}
		return entry.name.endsWith('.css') ? [path] : []
	})
}

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

/** Brace-aware rule extractor: yields {selector, body} for every ruleset. */
function* rules(css) {
	let i = 0
	while (i < css.length) {
		const open = css.indexOf('{', i)
		if (open === -1) {
			return
		}
		const selector = css.slice(css.lastIndexOf('}', i - 1) + 1, open).trim()
		let depth = 1
		let j = open + 1
		while (j < css.length && depth > 0) {
			if (css[j] === '{') depth++
			else if (css[j] === '}') depth--
			j++
		}
		yield { selector, body: css.slice(open + 1, j - 1) }
		i = j
	}
}

function sourceFiles(dir, exts) {
	try {
		return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
			const path = join(dir, entry.name)
			if (entry.isDirectory()) {
				return sourceFiles(path, exts)
			}
			return exts.some((ext) => entry.name.endsWith(ext)) ? [path] : []
		})
	} catch {
		return []
	}
}

/**
 * `select` as a delimited class/tag segment: matches `select`, `bck-select`,
 * `form-select`, `scope-strip__select`, `select--sm`; does NOT match
 * `selected`, `selector`, `selection` (suffix chars are word chars).
 */
const SELECT_SEGMENT = /(?<![A-Za-z0-9])select(?![A-Za-z0-9])/i

/** Classes provably applied to <select> elements in js/ + templates/. */
function selectAppliedClasses() {
	const classes = new Set()
	const addClassValue = (raw) => {
		for (const tok of raw.split(/\s+/)) {
			if (/^[A-Za-z_-][\w-]*$/.test(tok)) {
				classes.add(tok)
			}
		}
	}
	const dirs = [join(appRoot, 'js'), join(appRoot, 'templates')]
	for (const dir of dirs) {
		for (const file of sourceFiles(dir, ['.js', '.php'])) {
			const src = stripComments(readFileSync(file, 'utf8'))
			for (const m of src.matchAll(/<select\b[^>]*?\bclass\s*=\s*["']([^"']+)["']/gi)) {
				addClassValue(m[1])
			}
			for (const m of src.matchAll(/(?:\bcreateElement|\bel|\bh|\$)\(\s*["']select["']/gi)) {
				let segment = src.slice(m.index + m[0].length, m.index + m[0].length + 600)
				const nxt = segment.search(/(?:\bcreateElement|\bel|\bh|\$)\(/i)
				if (nxt !== -1) {
					segment = segment.slice(0, nxt)
				}
				for (const cm of segment.matchAll(/class(?:Name)?\s*[:=]\s*["']([^"']+)["']/g)) {
					addClassValue(cm[1])
				}
				for (const cl of segment.matchAll(/classList\.add\(([^)]*)\)/g)) {
					for (const qm of cl[1].matchAll(/["']([^"']+)["']/g)) {
						addClassValue(qm[1])
					}
				}
			}
		}
	}
	return classes
}

test('no select-segment rule uses the bare background: shorthand', () => {
	const offenders = []
	for (const file of cssFiles(cssRoot)) {
		const css = stripComments(readFileSync(file, 'utf8'))
		for (const { selector, body } of rules(css)) {
			// apply the segment test to each combinator-separated compound
			const hit = selector.split(/\s*[>+~,]\s*|\s+/).some(
				(compound) => SELECT_SEGMENT.test(compound))
			if (!hit) {
				continue
			}
			if (/(^|;)\s*background\s*:/.test(body)) {
				offenders.push(`${relative(appRoot, file)}: ${selector}`)
			}
		}
	}
	assert.deepEqual(offenders, [],
		`bare background: shorthand resets the chevron (use background-color:):\n${offenders.join('\n')}`)
})

test('no rule on a select-applied class uses the bare background: shorthand', () => {
	const selectClasses = selectAppliedClasses()
	const offenders = []
	for (const file of cssFiles(cssRoot)) {
		const css = stripComments(readFileSync(file, 'utf8'))
		for (const { selector, body } of rules(css)) {
			const hit = [...selectClasses].find((cls) =>
				new RegExp(`\\.${cls.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`).test(selector))
			if (hit && /(^|;)\s*background\s*:/.test(body)) {
				offenders.push(`${relative(appRoot, file)}: ${selector}  [class .${hit} applied to <select>]`)
			}
		}
	}
	assert.deepEqual(offenders, [],
		`class rules matching <select> reset the chevron where no qualified rule restores it (use background-color:):\n${offenders.join('\n')}`)
})

test('form-controls.css select chevron rule keeps image + position + repeat + size', () => {
	const css = stripComments(readFileSync(join(cssRoot, 'common/form-controls.css'), 'utf8'))
	const chevron = [...rules(css)].find((r) =>
		SELECT_SEGMENT.test(r.selector) && /background-image/.test(r.body))
	assert.ok(chevron, 'select chevron rule missing in common/form-controls.css')
	for (const prop of ['background-image', 'background-position', 'background-repeat', 'background-size']) {
		assert.match(chevron.body, new RegExp(`${prop}\\s*:`), `chevron rule lost ${prop}`)
	}
	assert.match(chevron.body, /appearance\s*:\s*none/, 'chevron rule lost appearance:none')
})

test('scope-strip select rule paints background-color, never the shorthand', () => {
	const css = stripComments(readFileSync(join(cssRoot, 'common/shell-chrome.css'), 'utf8'))
	const rule = [...rules(css)].find((r) => /\.bck-scope-strip__select\b/.test(r.selector))
	assert.ok(rule, '.bck-scope-strip__select rule missing in css/common/shell-chrome.css')
	assert.doesNotMatch(rule.body, /(^|;)\s*background\s*:/,
		'scope-strip select must not use the chevron-wiping background: shorthand')
	assert.match(rule.body, /background-color\s*:/,
		'scope-strip select must paint background-color (tpl @765ebafebc)')
})
