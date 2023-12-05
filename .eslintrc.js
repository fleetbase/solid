'use strict';

module.exports = {
    root: true,
    parser: 'babel-eslint',
    parserOptions: {
        ecmaVersion: 2018,
        sourceType: 'module',
        ecmaFeatures: {
            legacyDecorators: true,
        },
    },
    plugins: ['ember'],
    extends: ['eslint:recommended', 'plugin:ember/recommended', 'plugin:prettier/recommended'],
    env: {
        browser: true,
    },
    globals: {
        Stripe: 'readonly',
    },
    rules: {
        'ember/no-array-prototype-extensions': 'off',
        'ember/no-computed-properties-in-native-classes': 'off',
        'ember/no-controller-access-in-routes': 'off',
        'ember/no-empty-glimmer-component-classes': 'off',
        'ember/no-get': 'off',
        'ember/classic-decorator-no-classic-methods': 'off',
        'no-prototype-builtins': 'off',
        'node/no-unpublished-require': [
            'error',
            {
                allowModules: ['resolve', 'broccoli-funnel'],
            },
        ],
    },
    overrides: [
        // node files
        {
            files: [
                './.eslintrc.js',
                './.prettierrc.js',
                './.template-lintrc.js',
                './ember-cli-build.js',
                './index.js',
                './testem.js',
                './blueprints/*/index.js',
                './config/**/*.js',
                './tests/dummy/config/**/*.js',
            ],
            parserOptions: {
                sourceType: 'script',
            },
            env: {
                browser: false,
                node: true,
            },
            plugins: ['node'],
            extends: ['plugin:node/recommended'],
        },
        {
            // test files
            files: ['tests/**/*-test.{js,ts}'],
            extends: ['plugin:qunit/recommended'],
        },
    ],
};
