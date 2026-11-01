<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

/**
 * (Named so that Laravel does not discover it for every test.) Reverting an entry needs the `update` policy of the record; a record without a policy is
 * denied by default. Registered by the workbench panel only for the Revert demo.
 */
class RevertDemoArticlePolicy
{
    public function update(): bool
    {
        return true;
    }
}
