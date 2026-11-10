<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__) . '/src/bootstrap.php';

use Qenda\CampaignShare\AppFactory;
use Qenda\CampaignShare\Http\Request;

AppFactory::fromWeb(dirname(__DIR__))->handle(Request::fromGlobals())->emit();
