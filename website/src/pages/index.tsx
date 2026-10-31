import type {ReactNode} from 'react';
import {useState} from 'react';
import clsx from 'clsx';
import Link from '@docusaurus/Link';
import Translate, {translate} from '@docusaurus/Translate';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import Layout from '@theme/Layout';
import CodeBlock from '@theme/CodeBlock';
import Heading from '@theme/Heading';

import styles from './index.module.css';

const INSTALL = 'composer require --dev ddr/sonda';

function InstallCommand() {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    await navigator.clipboard.writeText(INSTALL);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  return (
    <button type="button" className={styles.install} onClick={copy} aria-label="Copy install command">
      <span>$</span>
      <span>{INSTALL}</span>
      <span className={styles.dim}>{copied ? '✓' : '⧉'}</span>
    </button>
  );
}

function Terminal() {
  return (
    <div className={styles.terminal} aria-label="Sonda output">
      <div className={styles.terminalBar}>
        <i />
        <i />
        <i />
      </div>
      <div className={styles.terminalBody}>
        <span className={styles.prompt}>$ </span>vendor/bin/sonda test --changed=origin/master{'\n'}
        <span className={styles.dim}>PASS  Tests\Feature\OrderTest{'\n'}</span>
        <span className={styles.dim}>Tests:  4514 passed (12031 assertions){'\n'}</span>
        <span className={styles.dim}>Duration: 174.20s{'\n\n'}</span>
        <span className={styles.ok}>Coverage: lines 12118/12118 (100.00%), branches 4857/4857 (100.00%)</span>
        {'\n'}
        <span className={styles.ok}>Of what changed: lines 86/86 (100.00%), branches 31/31 (100.00%)</span>
        {'\n'}
        The changed files are 100% covered.{'\n\n'}
        <span className={styles.prompt}>$ </span>vendor/bin/sonda test --files=app/Models/Order.php{'\n'}
        <span className={styles.warn}>Uncovered:</span>
        {'\n'}app/Models/Order.php: lines 48, 71-73; branches 48, 70-74
      </div>
    </div>
  );
}

function Hero() {
  return (
    <header className={styles.hero}>
      <div className="container">
        <div className={styles.heroGrid}>
          <div>
            <div className={styles.eyebrow}>
              <Translate id="home.eyebrow">PHP coverage, instrumented</Translate>
            </div>
            <Heading as="h1" className={styles.title}>
              <Translate id="home.title.before">Line and </Translate>
              <em>
                <Translate id="home.title.em">branch</Translate>
              </em>
              <Translate id="home.title.after"> coverage for PHP. No Xdebug, no PCOV.</Translate>
            </Heading>
            <p className={styles.lead}>
              <Translate id="home.lead">
                Sonda inserts probes into your source before it loads, the way Istanbul does for
                JavaScript. The suite runs at its normal speed, and every decision has to show both
                outcomes.
              </Translate>
            </p>
            <div className={styles.actions}>
              <Link className="button button--primary button--lg" to="/docs/getting-started">
                <Translate id="home.cta.start">Get started</Translate>
              </Link>
              <Link className="button button--secondary button--outline button--lg" to="/docs/comparison-with-xdebug">
                <Translate id="home.cta.compare">Numbers vs Xdebug</Translate>
              </Link>
              <InstallCommand />
            </div>
          </div>
          <Terminal />
        </div>
      </div>
    </header>
  );
}

type Bar = {label: string; value: string; share: number; slow?: boolean};

function Measurements() {
  const bars: Bar[] = [
    {label: translate({id: 'home.bars.none', message: 'No coverage'}), value: '178s', share: 26},
    {label: translate({id: 'home.bars.sonda', message: 'Sonda, lines and branches'}), value: '174s', share: 25},
    {label: translate({id: 'home.bars.xdebugLines', message: 'Xdebug, lines only'}), value: '671s', share: 100, slow: true},
    {
      label: translate({id: 'home.bars.xdebugPaths', message: 'Xdebug --path-coverage'}),
      value: translate({id: 'home.bars.unfinished', message: '> 63 min'}),
      share: 100,
      slow: true,
    },
  ];

  return (
    <section className={clsx(styles.section, styles.sectionAlt)}>
      <div className="container">
        <Heading as="h2" className={styles.sectionTitle}>
          <Translate id="home.measure.title">The suite runs at its own speed</Translate>
        </Heading>
        <p className={styles.sectionLead}>
          <Translate id="home.measure.lead">
            A Laravel 13 application with Filament, Pest 4 and Postgres: 4,514 tests, measured on the
            same machine. Xdebug with path coverage did not finish in an hour.
          </Translate>
        </p>
        <div className={styles.bars}>
          {bars.map((bar) => (
            <div key={bar.label} className={styles.bar}>
              <span className={styles.barLabel}>{bar.label}</span>
              <div className={styles.barTrack}>
                <div className={clsx(styles.barFill, bar.slow && styles.slow)} style={{width: `${bar.share}%`}} />
              </div>
              <span className={styles.barValue}>{bar.value}</span>
            </div>
          ))}
        </div>
        <p className={styles.note}>
          <Translate id="home.measure.note">
            Memory: 308 MB without coverage, 317 MB with Sonda, 640 MB with Xdebug lines only. With
            ParaTest and 10 workers: 42s without coverage, 40s with Sonda.
          </Translate>
        </p>
      </div>
    </section>
  );
}

function Features() {
  const features = [
    {
      icon: '{ }',
      title: translate({id: 'home.f1.title', message: 'Branches, not just lines'}),
      body: translate({
        id: 'home.f1.body',
        message: 'Every outcome of if, loops, match, ?:, ??, ?-> and short-circuit operators is a branch with its own probe.',
      }),
    },
    {
      icon: '0',
      title: translate({id: 'home.f2.title', message: 'No extension'}),
      body: translate({
        id: 'home.f2.body',
        message: 'Nothing to install on the runner. Any PHP 8.4+ with your vendor/ is enough; the report comes from php-code-coverage.',
      }),
    },
    {
      icon: '≠',
      title: translate({id: 'home.f3.title', message: 'Strict by default'}),
      body: translate({
        id: 'home.f3.body',
        message: 'An if without else still needs both outcomes tested. Sonda synthesizes the else and counts it.',
      }),
    },
    {
      icon: 'Δ',
      title: translate({id: 'home.f4.title', message: 'Only what changed'}),
      body: translate({
        id: 'home.f4.body',
        message: '--changed measures the files your branch touched and fails if a line or branch of them stays uncovered.',
      }),
    },
    {
      icon: '⇢',
      title: translate({id: 'home.f5.title', message: 'Only the affected tests'}),
      body: translate({
        id: 'home.f5.body',
        message: '--affected runs just the tests that executed the changed files, from a map the previous run saved.',
      }),
    },
    {
      icon: '@',
      title: translate({id: 'home.f6.title', message: 'Blade views too'}),
      body: translate({
        id: 'home.f6.body',
        message: 'Compiled templates are instrumented and mapped back to the view, line and branch, including custom directives.',
      }),
    },
  ];

  return (
    <section className={styles.section}>
      <div className="container">
        <Heading as="h2" className={styles.sectionTitle}>
          <Translate id="home.features.title">What you get</Translate>
        </Heading>
        <p className={styles.sectionLead}>
          <Translate id="home.features.lead">
            Built for a merge gate: 100% of the lines and branches of what the merge request changed,
            in the time the suite already takes.
          </Translate>
        </p>
        <div className={styles.features}>
          {features.map((feature) => (
            <div key={feature.title} className={styles.feature}>
              <div className={styles.icon}>{feature.icon}</div>
              <Heading as="h3">{feature.title}</Heading>
              <p>{feature.body}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

const BEFORE = `public function total(): int
{
    if ($this->items === []) {
        return 0;
    }

    return $this->sum() + $this->shipping;
}`;

const AFTER = `public function total(): int
{
    $h = &Hits::$current['a1b2c3'];
    $h[1] = 1;
    if ($this->items === []) {
        $h[2] = 1;
        return 0;
    } else { $h[3] = 1; }

    $h[4] = 1;
    return $this->sum() + $this->shipping;
}`;

function HowItWorks() {
  return (
    <section className={clsx(styles.section, styles.sectionAlt)}>
      <div className="container">
        <Heading as="h2" className={styles.sectionTitle}>
          <Translate id="home.how.title">How it works</Translate>
        </Heading>
        <p className={styles.sectionLead}>
          <Translate id="home.how.lead">
            No opcode interception and no path enumeration, which is what makes Xdebug slow. A probe is
            one array assignment, and the instrumented copy keeps every line number.
          </Translate>
        </p>
        <div className={styles.steps}>
          <div className={styles.step}>
            <Heading as="h3">
              <Translate id="home.how.s1.title">Instrument</Translate>
            </Heading>
            <p>
              <Translate id="home.how.s1.body">
                Each file of your phpunit.xml source gets a probe at every branch outcome. Copies are
                cached by file hash, so a second run instruments nothing.
              </Translate>
            </p>
          </div>
          <div className={styles.step}>
            <Heading as="h3">
              <Translate id="home.how.s2.title">Run</Translate>
            </Heading>
            <p>
              <Translate id="home.how.s2.body">
                A bootstrap registers an autoloader ahead of Composer's that loads the copies. PHPUnit,
                Pest, ParaTest and artisan test run untouched; each worker saves what it hit.
              </Translate>
            </p>
          </div>
          <div className={styles.step}>
            <Heading as="h3">
              <Translate id="home.how.s3.title">Report</Translate>
            </Heading>
            <p>
              <Translate id="home.how.s3.body">
                Hits become a php-code-coverage report: HTML, Clover, Cobertura for the GitLab diff and
                Code Quality annotations on the uncovered lines.
              </Translate>
            </p>
          </div>
        </div>
        <div className={styles.diff}>
          <div>
            <h4>
              <Translate id="home.how.before">Your code</Translate>
            </h4>
            <CodeBlock language="php">{BEFORE}</CodeBlock>
          </div>
          <div>
            <h4>
              <Translate id="home.how.after">What runs (simplified)</Translate>
            </h4>
            <CodeBlock language="php">{AFTER}</CodeBlock>
          </div>
        </div>
      </div>
    </section>
  );
}

function Cta() {
  return (
    <section className={styles.cta}>
      <div className="container">
        <Heading as="h2" className={styles.sectionTitle}>
          <Translate id="home.cta2.title">Two commands to your first report</Translate>
        </Heading>
        <p>
          <Translate id="home.cta2.lead">
            Composer, then sonda test from the project root. PHP 8.4+, PHPUnit 12 or Pest 4.
          </Translate>
        </p>
        <Link className="button button--primary button--lg" to="/docs/getting-started">
          <Translate id="home.cta2.button">Read the getting started guide</Translate>
        </Link>
      </div>
    </section>
  );
}

export default function Home(): ReactNode {
  const {siteConfig} = useDocusaurusContext();

  return (
    <Layout title={siteConfig.title} description={siteConfig.tagline}>
      <Hero />
      <Measurements />
      <Features />
      <HowItWorks />
      <Cta />
    </Layout>
  );
}
