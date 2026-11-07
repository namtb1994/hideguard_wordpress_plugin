<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\AccessDecision;

/** Native action handlers retain the same decision used by the existing gate. */
final class ActionDenied extends \RuntimeException
{
    public function __construct(public readonly AccessDecision $decision)
    {
        parent::__construct('Verify your access before continuing.');
    }
}
