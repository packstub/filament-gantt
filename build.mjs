/**
 * Builds resources/dist/gantt.{js,css} from resources/js/gantt.js and
 * resources/css/gantt.css: minified with esbuild, the JS as an ES module (the
 * Alpine component Filament loads with x-load). The output is committed;
 * consumers run only `filament:assets`.
 *
 *   npm run build          once
 *   npm run dev            rebuild on change
 */

import * as esbuild from 'esbuild'
import { readFileSync, statSync } from 'node:fs'
import { gzipSync } from 'node:zlib'

const watch = process.argv.includes('--watch')

const banner = '/*! packstub/filament-gantt — MIT, https://packstub.dev/docs/filament-gantt */'

const common = {
    bundle: true,
    minify: true,
    legalComments: 'none',
    logLevel: 'info',
    // Filament 4/5's own browser floor: anything older cannot run the panel either.
    target: ['es2020', 'chrome90', 'firefox90', 'safari15'],
}

const js = {
    ...common,
    entryPoints: ['resources/js/gantt.js'],
    outfile: 'resources/dist/gantt.js',
    format: 'esm',
    banner: { js: banner },
}

const css = {
    ...common,
    entryPoints: ['resources/css/gantt.css'],
    outfile: 'resources/dist/gantt.css',
    banner: { css: banner },
}

if (watch) {
    const contexts = await Promise.all([esbuild.context(js), esbuild.context(css)])
    await Promise.all(contexts.map((c) => c.watch()))
    console.log('watching resources/js and resources/css…')
} else {
    await Promise.all([esbuild.build(js), esbuild.build(css)])
    for (const file of ['resources/dist/gantt.js', 'resources/dist/gantt.css']) {
        const kb = (bytes) => (bytes / 1024).toFixed(1)
        console.log(`${file}: ${kb(statSync(file).size)} KB, ${kb(gzipSync(readFileSync(file)).length)} KB gzip`)
    }
}
