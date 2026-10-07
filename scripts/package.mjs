import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const slug = 'multi-level-category-menu';
export const runtimePaths = [
    'multi-level-category-menu.php', 'uninstall.php', 'readme.md', 'assets', 'includes'
];
const stableVersion = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/;
const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export async function validateVersion(root = projectRoot, tag) {
    const [php, pkg, block, readme] = await Promise.all([
        readFile(join(root, 'multi-level-category-menu.php'), 'utf8'),
        readFile(join(root, 'package.json'), 'utf8'),
        readFile(join(root, 'assets/js/block.json'), 'utf8'),
        readFile(join(root, 'readme.md'), 'utf8')
    ]);
    const versions = [
        php.match(/^Version:\s*(\S+)\s*$/m)?.[1],
        JSON.parse(pkg).version,
        JSON.parse(block).version,
        readme.match(/^Version:\s*\*\*([^*]+)\*\*\s*$/m)?.[1]
    ];
    const version = versions[0];
    if (!version || !stableVersion.test(version) || versions.some(value => value !== version)) {
        throw new Error('Version mismatch: PHP header, package.json, block.json and README must share one X.Y.Z version.');
    }
    if (tag !== undefined && tag !== `v${version}`) {
        throw new Error(`Expected tag v${version}; received ${tag}. Only stable vX.Y.Z tags are supported.`);
    }
    return version;
}

export async function buildPackage(root = projectRoot, tag, outputDirectory = join(root, 'dist')) {
    const version = await validateVersion(root, tag);
    // Package exactly the checked-out commit, never local dependencies, secrets,
    // untracked files or dirty runtime files.
    execFileSync('git', ['diff', '--exit-code', 'HEAD', '--', ...runtimePaths], { cwd: root, stdio: 'pipe' });
    const tree = execFileSync('git', ['ls-tree', '-r', 'HEAD', '--', ...runtimePaths], { cwd: root, encoding: 'utf8' });
    if (tree.split('\n').some(line => line.startsWith('120000 ') || line.startsWith('160000 '))) {
        throw new Error('Runtime package must not contain symlinks or submodules.');
    }
    await mkdir(outputDirectory, { recursive: true });
    const archive = join(outputDirectory, `${slug}-${version}.zip`);
    execFileSync('git', [
        'archive', '--format=zip', `--prefix=${slug}/`, `--output=${archive}`, 'HEAD', '--', ...runtimePaths
    ], { cwd: root, stdio: 'pipe' });
    const sha256 = createHash('sha256').update(await readFile(archive)).digest('hex');
    await writeFile(`${archive}.sha256`, `${sha256}  ${slug}-${version}.zip\n`);
    return { archive, sha256, version };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const args = process.argv.slice(2);
        if (args[0] === '--check') {
            console.log(`Validated version ${await validateVersion(projectRoot, args[1])}`);
        } else {
            const result = await buildPackage(projectRoot, args[0]);
            console.log(`Created ${result.archive}\nSHA256 ${result.sha256}`);
        }
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
