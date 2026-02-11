<?php

foreach (glob(__DIR__ . '/helpers/*.php') as $filename) {
    require_once $filename;
}

EnvHelper::loadEnv(__DIR__ . '/.env');