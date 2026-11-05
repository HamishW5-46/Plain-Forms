const path = require('node:path');
const { createRequire } = require('node:module');
const load = process.env.PLAINFORMS_NODE_MODULES
    ? createRequire(
          path.join(process.env.PLAINFORMS_NODE_MODULES, '../package.json'),
      )
    : require;
load('esbuild').buildSync({
    entryPoints: [path.join(__dirname, '../assets/src/admin/index.jsx')],
    outfile: path.join(__dirname, '../assets/admin-app.js'),
    bundle: true,
    minify: true,
    format: 'iife',
    target: ['es2020'],
    jsxFactory: 'h',
    jsxFragment: 'Fragment',
    legalComments: 'none',
    banner: {
        js: '/* Plain Forms React admin. Generated from assets/src/admin; run npm run build. */',
    },
});
