import { readdir, readFile, writeFile } from 'node:fs/promises';
import { minify } from 'terser';
import CleanCSS from 'clean-css';

for (const type of ['js', 'css']) {
    const directory = new URL(`../assets/${type}/`, import.meta.url);
    for (const file of (await readdir(directory)).sort()) {
        if (!file.endsWith(`.${type}`) || file.endsWith(`.min.${type}`)) continue;
        const source = await readFile(new URL(file, directory), 'utf8');
        const result = type === 'js'
            ? await minify(source, { compress: true, mangle: true, format: { comments: false } })
            : new CleanCSS({ level: 2 }).minify(source);
        if (result.errors?.length) throw new Error(result.errors.join('\n'));
        const output = (result.code ?? result.styles) + '\n';
        await writeFile(new URL(file.replace(`.${type}`, `.min.${type}`), directory), output);
        console.log(`${file}: ${Buffer.byteLength(source)} -> ${Buffer.byteLength(output)} bytes`);
    }
}
