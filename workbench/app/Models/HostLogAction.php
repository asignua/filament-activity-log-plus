<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentActivityLogPlus\Actions\LogActivityAction;

/**
 * A host's own log action (it must still extend the plugin's one to keep the stamps).
 */
class HostLogAction extends LogActivityAction {}
