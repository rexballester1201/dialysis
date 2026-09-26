import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

/**
 * A cheap sweep, in the spirit of the API's ModelIntegrityTest.
 *
 * `window.confirm`, `window.alert` and `window.prompt` block the JavaScript
 * thread -- on the bedside PWA that stalls the outbox and the sync loop -- and
 * none of them can collect the reason that this system requires whenever a
 * clinical record is overridden or rewritten. ConfirmDialog.tsx explains this at
 * length; this test is what stops the explanation from being ignored.
 *
 * The sweep is deliberately dumb: it reads source text rather than parsing it,
 * because a guard nobody can understand is a guard that gets deleted the first
 * time it fails.
 */

const REPO_ROOT = fileURLToPath(new URL('../../..', import.meta.url))

/**
 * packages/ui is excluded because it is the one place allowed to name these
 * functions -- in prose, explaining why they are not used.
 */
const SCANNED = [
  'apps/bedside/src',
  'apps/console/src',
  'packages/domain/src',
  'packages/api-client/src',
]

const EXTENSIONS = ['.ts', '.tsx']

function sourceFiles(directory: string): string[] {
  let entries: string[]

  try {
    entries = readdirSync(directory)
  } catch {
    return [] // A workspace that has not been created yet is not a failure.
  }

  return entries.flatMap((entry) => {
    const path = join(directory, entry)

    if (statSync(path).isDirectory()) return sourceFiles(path)

    return EXTENSIONS.some((extension) => path.endsWith(extension)) ? [path] : []
  })
}

/**
 * Blank out comments so prose about these functions does not trip the sweep.
 *
 * A block comment is replaced by its own newlines rather than removed, so the
 * line numbers this test reports still match the file a reader opens. Removing
 * them outright shifts every line after a docblock, which sends the reader to
 * the wrong place -- worse than not reporting a line at all.
 *
 * Truncating at `//` will also cut a URL inside a string literal. That can only
 * ever hide a call, never invent one, so the worst case is a miss rather than a
 * false accusation.
 */
function withoutComments(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, (comment) => comment.replace(/[^\n]/g, ''))
    .replace(/\/\/.*$/gm, '')
}

const NATIVE_CALL = /(?<![.\w$])(?:(?:window|globalThis|self)\s*\.\s*)?(confirm|alert|prompt)\s*\(/

describe('native browser dialogs', () => {
  it('are not called anywhere in the client source', () => {
    const offences: string[] = []

    for (const relative of SCANNED) {
      for (const file of sourceFiles(join(REPO_ROOT, relative))) {
        const lines = withoutComments(readFileSync(file, 'utf8')).split(/\r?\n/)

        lines.forEach((line, index) => {
          const match = NATIVE_CALL.exec(line)

          if (match !== null) {
            const path = file.slice(REPO_ROOT.length).replace(/\\/g, '/')
            offences.push(`${path}:${index + 1}  ${line.trim()}`)
          }
        })
      }
    }

    expect(
      offences,
      [
        'Native browser dialogs are not used in this repo.',
        '',
        'Use the shared dialog instead:',
        '',
        "  import { useConfirm } from '@dialysis/ui'",
        '  const confirmAction = useConfirm()',
        '  const answer = await confirmAction({ title: ..., reason: { label: ..., minLength: 10 } })',
        '',
        'Name the variable `confirmAction`, not `confirm` -- a bare `confirm(` is',
        'indistinguishable from the native global, both to this sweep and to a reader.',
        '',
        `Found:\n${offences.join('\n')}`,
      ].join('\n'),
    ).toEqual([])
  })

  it('catches a call it is meant to catch', () => {
    // Proves the pattern works, so a sweep that has quietly stopped matching
    // cannot pass as "nothing found".
    const samples = [
      'if (window.confirm("really?")) {',
      'const ok = confirm("really?")',
      'globalThis.alert("hi")',
      'const name = prompt("who?")',
      'self.confirm("really?")',
    ]

    for (const sample of samples) {
      expect(NATIVE_CALL.test(sample), sample).toBe(true)
    }
  })

  it('leaves legitimate code alone', () => {
    const samples = [
      'const answer = await confirmAction({ title: "x" })',
      'this.confirm(x)',
      'form.confirmPassword',
      'const confirmed = result.confirmed',
      'logger.alertThreshold = 3',
      'promptSpec.minLength',
    ]

    for (const sample of samples) {
      expect(NATIVE_CALL.test(sample), sample).toBe(false)
    }
  })
})
