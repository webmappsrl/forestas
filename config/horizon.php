<?php

return [
    'environments' => [
        'staging' => [
            'supervisor-default' => [
                'maxProcesses' => 2,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-pbf' => [
                'maxProcesses' => 4,
                'minProcesses' => 2,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-layers' => [
                'maxProcesses' => 2,
                'minProcesses' => 1,
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],
    ],
];
