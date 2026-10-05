const js = require("@eslint/js");
const globals = require("globals");

module.exports = [
  {
    ignores: ["node_modules/**", "vendor/**"],
  },
  {
    files: ["Js/**/*.js"],
    ...js.configs.recommended,
    languageOptions: {
      globals: globals.browser,
    },
  },
];
