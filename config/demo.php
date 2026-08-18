<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | Turns on the one-click login used by the public sales demo. It is off by
    | default and must only ever be enabled on a throwaway instance: it lets
    | anyone who can reach the site sign in without a password.
    |
    | When off, the demo login route is not registered at all — it 404s rather
    | than merely being hidden — and the button is not rendered.
    |
    */

    'enabled' => (bool) env('DEMO_MODE', false),

    /*
    | The account the button signs in as. Created by DemoDataSeeder as an
    | accountant, never a super admin, so Administration stays out of reach of
    | anyone walking in off the public URL.
    */

    'user_email' => env('DEMO_USER_EMAIL', 'demo@harkcreation.com'),

];
