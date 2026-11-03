<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

/** php-code-coverage 12. */
final class V12CoverageApi implements CoverageApi
{
    use Coverage12AndOlder;
    use ObjectCoverageData;
}
