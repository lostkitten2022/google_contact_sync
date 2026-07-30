<?php
return [
    'routes' => [
        ['name' => 'sync#getStatus', 'url' => '/status', 'verb' => 'GET'],
        ['name' => 'sync#setStatus', 'url' => '/status', 'verb' => 'POST']
    ]
];
