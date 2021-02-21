<?php declare(strict_types=1);
namespace Orklah\NotEmpty;

use Orklah\NotEmpty\Hooks\NotEmptyHooks;
use SimpleXMLElement;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        if(class_exists(NotEmptyHooks::class)){
            $registration->registerHooksFromClass(NotEmptyHooks::class);
        }
    }
}
