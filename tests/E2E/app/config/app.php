<?php

return [
    'debug_mode' => true,
    'public_directory' => 'public',
    'template_engine' => 'smarty',
    'controller_directory' => dirname(__DIR__) . '/src/Controllers',
    'error_handler_directory' => dirname(__DIR__) . '/src/Errors',
    'aspect_directory' => dirname(__DIR__) . '/src/Aspects',
    'scheduler_directory' => dirname(__DIR__) . '/src/Tasks',
    'monolog_file' => dirname(__DIR__) . '/storage/logs/canvas.log',
    'match_trailing_slashes' => false,
];
