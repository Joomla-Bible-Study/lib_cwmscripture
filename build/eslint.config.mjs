// ESLint flat-config for lib_cwmscripture.
//
// Imports the shared base from cwm-build-tools and adds lib-specific
// browser globals on top. To update the base ruleset, change it
// upstream in cwm-build-tools (templates/eslint.config.base.mjs)
// and bump the dep here.

import baseConfig from '../vendor/cwm/build-tools/templates/eslint.config.base.mjs';

export default [
    ...baseConfig,
    {
        files: ['**/*.js', '**/*.mjs', '**/*.es6.js'],
        languageOptions: {
            globals: {
                CwmScriptureFetch: 'readonly',
            },
        },
    },
];
