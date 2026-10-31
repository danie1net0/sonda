import {themes as prismThemes} from 'prism-react-renderer';
import type {Config} from '@docusaurus/types';
import type * as Preset from '@docusaurus/preset-classic';

// Runs in Node.js: no browser APIs or JSX here.

const config: Config = {
  title: 'Sonda',
  tagline: 'Line and branch coverage for PHP, by instrumenting the source. No Xdebug, no PCOV.',
  favicon: 'img/favicon.svg',

  future: {
    v4: true,
    faster: true,
  },

  url: 'https://danie1net0.github.io',
  baseUrl: '/sonda/',
  organizationName: 'danie1net0',
  projectName: 'sonda',
  trailingSlash: false,

  onBrokenLinks: 'throw',

  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'pt-BR'],
    localeConfigs: {
      en: {label: 'English'},
      'pt-BR': {label: 'Português (Brasil)'},
    },
  },

  headTags: [
    {tagName: 'link', attributes: {rel: 'preconnect', href: 'https://fonts.googleapis.com'}},
    {tagName: 'link', attributes: {rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: 'anonymous'}},
    {
      tagName: 'link',
      attributes: {
        rel: 'stylesheet',
        href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap',
      },
    },
  ],

  presets: [
    [
      'classic',
      {
        docs: {
          sidebarPath: './sidebars.ts',
          editUrl: 'https://github.com/danie1net0/sonda/tree/master/website/',
          showLastUpdateTime: false,
        },
        blog: {
          showReadingTime: true,
          blogTitle: 'Sonda blog',
          blogDescription: 'Releases, measurements and notes on coverage in PHP.',
          feedOptions: {type: ['rss', 'atom'], xslt: true},
          editUrl: 'https://github.com/danie1net0/sonda/tree/master/website/',
          onInlineTags: 'warn',
          onInlineAuthors: 'warn',
          onUntruncatedBlogPosts: 'warn',
        },
        theme: {
          customCss: './src/css/custom.css',
        },
      } satisfies Preset.Options,
    ],
  ],

  themeConfig: {
    image: 'img/social-card.jpg',
    colorMode: {
      defaultMode: 'dark',
      respectPrefersColorScheme: true,
    },
    announcementBar: {
      id: 'packagist',
      content:
        'Sonda is on Packagist: <code>composer require --dev ddr/sonda</code> · <a href="https://github.com/danie1net0/sonda">Star it on GitHub</a>',
      isCloseable: true,
    },
    navbar: {
      title: 'Sonda',
      logo: {alt: 'Sonda', src: 'img/logo.svg'},
      items: [
        {type: 'docSidebar', sidebarId: 'docs', position: 'left', label: 'Docs'},
        {to: '/docs/comparison-with-xdebug', label: 'vs Xdebug', position: 'left'},
        {to: '/blog', label: 'Blog', position: 'left'},
        {type: 'localeDropdown', position: 'right'},
        {
          href: 'https://packagist.org/packages/ddr/sonda',
          label: 'Packagist',
          position: 'right',
        },
        {
          href: 'https://github.com/danie1net0/sonda',
          position: 'right',
          className: 'header-github-link',
          'aria-label': 'GitHub repository',
        },
      ],
    },
    footer: {
      style: 'dark',
      links: [
        {
          title: 'Docs',
          items: [
            {label: 'Getting started', to: '/docs/getting-started'},
            {label: 'CLI reference', to: '/docs/usage/cli-reference'},
            {label: 'Branch semantics', to: '/docs/branch-semantics'},
            {label: 'In CI', to: '/docs/ci/gitlab'},
          ],
        },
        {
          title: 'Project',
          items: [
            {label: 'GitHub', href: 'https://github.com/danie1net0/sonda'},
            {label: 'Packagist', href: 'https://packagist.org/packages/ddr/sonda'},
            {label: 'Changelog', href: 'https://github.com/danie1net0/sonda/blob/master/CHANGELOG.md'},
            {label: 'Issues', href: 'https://github.com/danie1net0/sonda/issues'},
          ],
        },
        {
          title: 'More',
          items: [
            {label: 'Blog', to: '/blog'},
            {label: 'Contributing', to: '/docs/contributing'},
          ],
        },
      ],
      copyright: `MIT © ${new Date().getFullYear()} Daniel Neto`,
    },
    prism: {
      theme: prismThemes.github,
      darkTheme: prismThemes.oneDark,
      additionalLanguages: ['php', 'bash', 'yaml', 'json', 'diff'],
    },
    tableOfContents: {minHeadingLevel: 2, maxHeadingLevel: 3},
  } satisfies Preset.ThemeConfig,
};

export default config;
