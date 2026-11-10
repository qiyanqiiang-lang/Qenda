<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

final class AppFactory
{
    public static function fromWeb(string $root): App
    {
        return App::disabled(Config::load($root)->withEnabled(false));
    }
}
