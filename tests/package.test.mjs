import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, writeFile, rm, copyFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { buildPackage, validateVersion, slug } from '../scripts/package.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

test('release metadata agrees; wrong version, malformed and prerelease tags are rejected', async () => {
    const version = await validateVersion(root);
    assert.equal(await validateVersion(root, `v${version}`), version);
    for (const tag of ['v99.99.99', version, `v${version}-rc.1`, `v${version}\n`]) {
        await assert.rejects(validateVersion(root, tag), /Expected tag/);
    }
});

test('inconsistent version metadata is rejected before packaging', async () => {
    const temporary = await mkdtemp(join(tmpdir(), 'mlcm-version-'));
    try {
        await mkdir(join(temporary, 'assets/js'), { recursive: true });
        for (const file of ['multi-level-category-menu.php', 'package.json', 'assets/js/block.json', 'readme.md']) {
            await copyFile(join(root, file), join(temporary, file));
        }
        await writeFile(join(temporary, 'package.json'), JSON.stringify({ version: '99.99.99' }));
        await assert.rejects(validateVersion(temporary), /Version mismatch/);
    } finally {
        await rm(temporary, { recursive: true, force: true });
    }
});

test('WordPress ZIP has only runtime files, correct root/version and a reproducible checksum', async () => {
    const temporary = await mkdtemp(join(tmpdir(), 'mlcm-package-'));
    try {
        const result = await buildPackage(root, undefined, temporary);
        // Python's standard-library ZIP reader also verifies archive CRCs.
        const entries = JSON.parse(execFileSync('python3', ['-c',
            'import json,sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print(json.dumps(z.namelist()))',
            result.archive], { encoding: 'utf8' }));
        assert.ok(entries.every(name => name.startsWith(`${slug}/`) && !name.includes('..')));
        assert.ok(entries.includes(`${slug}/multi-level-category-menu.php`));
        assert.ok(entries.includes(`${slug}/includes/widget.php`));
        for (const name of ['frontend', 'admin', 'block-editor']) {
            assert.ok(entries.includes(`${slug}/assets/js/${name}.min.js`));
            assert.ok(entries.includes(`${slug}/assets/css/${name}.min.css`));
        }
        assert.ok(!entries.some(name => /\/(?:docs|tests|scripts|node_modules|\.github|\.git|dist)(?:\/|$)/.test(name)));
        assert.ok(!entries.some(name => /\/package(?:-lock)?\.json$/.test(name)));
        const php = execFileSync('python3', ['-c',
            'import sys,zipfile; print(zipfile.ZipFile(sys.argv[1]).read(sys.argv[2]).decode())',
            result.archive, `${slug}/multi-level-category-menu.php`], { encoding: 'utf8' });
        assert.ok(php.includes(`Version: ${result.version}`));
        const expected = `${result.sha256}  ${slug}-${result.version}.zip\n`;
        assert.equal(await readFile(`${result.archive}.sha256`, 'utf8'), expected);
        assert.equal((await buildPackage(root, undefined, temporary)).sha256, result.sha256);
    } finally {
        await rm(temporary, { recursive: true, force: true });
    }
});
