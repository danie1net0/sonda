# Sonda documentation

Docusaurus site published at https://danie1net0.github.io/sonda by `.github/workflows/docs.yml` on
every push to `master` that touches `website/`.

```bash
npm ci
npm start                       # English
npm start -- --locale pt-BR     # Portuguese
npm run build                   # both locales, as CI does
npm run write-translations -- --locale pt-BR   # refresh the pt-BR JSON files after changing the UI strings
```

English pages live in `docs/`; the Portuguese ones in `i18n/pt-BR/docusaurus-plugin-content-docs/current/`,
same file names.
