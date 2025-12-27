<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Table
    |--------------------------------------------------------------------------
    |
    | Laravel uses this table to store all database notifications.
    | You can change it to match your own table name.
    |
    */

    'table' => 'Conx_Notifications_T',

    /*
    |--------------------------------------------------------------------------
    | Default Notification Driver
    |--------------------------------------------------------------------------
    |
    | This is the default driver used by the notification system.
    | You can leave it as 'database' since we store notifications in the DB.
    |
    */

    'default' => env('NOTIFICATION_DRIVER', 'database'),

];
